<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\BootConfig\BootConfigPatcher;
use PHPMachineEmulator\Disk\HardDisk;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Stream\ISO\ISO9660;
use PHPMachineEmulator\Stream\ISO\ElTorito;
use PHPMachineEmulator\Stream\ISO\ISOBootImageStream;

class Disk implements InterruptInterface
{
    private const SECTOR_SIZE = BIOS::READ_SIZE_PER_SECTOR;
    private const CD_SECTOR_SIZE = ISO9660::SECTOR_SIZE;
    private const SECTORS_PER_TRACK = 63;
    private const HEADS_PER_CYLINDER = 16;
    private const FLOPPY_CYLINDERS = 80;
    private const FLOPPY_HEADS = 2;
    private const FLOPPY_SECTORS_PER_TRACK = 18;
    private const ELTORITO_EMULATED_FLOPPY_DRIVE = 0x10;
    private ?bool $traceInt13Caller = null;
    private ?int $traceInt13ReadsLimit = null;
    private int $traceInt13Reads = 0;
    /** @var array<int,true>|null */
    private ?array $stopOnInt13ReadLbaSet = null;
    private ?BootConfigPatcher $bootConfigPatcher = null;
    private bool $bootConfigPatchAnnounced = false;

    public function __construct(protected RuntimeInterface $runtime)
    {
    }

    public function process(RuntimeInterface $runtime): void
    {
        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        $ah = $ax->asHighBit();    // AH
        $al = $ax->asLowBit();   // AL

        $runtime->option()->logger()->debug(sprintf(
            'INT 13h: AH=0x%02X AL=0x%02X DL=0x%02X',
            $ah,
            $al,
            $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit()
        ));

        match ($ah) {
            0x00 => $this->reset($runtime),
            0x02 => $this->readSectorsCHS($runtime, $al),
            0x03 => $this->writeSectorsCHS($runtime, $al),
            0x41 => $this->extensionsPresent($runtime),
            0x42 => $this->readSectorsLBA($runtime),
            0x43 => $this->writeSectorsLBA($runtime),
            0x48 => $this->getDriveParametersExtended($runtime),
            0x08 => $this->getDriveParameters($runtime),
            0x15 => $this->getDiskType($runtime),
            0x4B => $this->handleBootInfo($runtime, $al),
            default => $this->unsupported($runtime, $ah),
        };
    }

    private function traceInt13ReadsLimit(RuntimeInterface $runtime): int
    {
        if ($this->traceInt13ReadsLimit !== null) {
            return $this->traceInt13ReadsLimit;
        }

        $limit = $runtime->logicBoard()->debug()->trace()->traceInt13ReadsLimit;
        $this->traceInt13ReadsLimit = max(0, $limit);
        return $this->traceInt13ReadsLimit;
    }

    private function maybeTraceInt13Read(RuntimeInterface $runtime, string $kind, int $lba, int $sectorCount, int $bufferAddress, bool $isCdrom): void
    {
        $limit = $this->traceInt13ReadsLimit($runtime);
        if ($limit <= 0 || $this->traceInt13Reads >= $limit) {
            return;
        }
        $this->traceInt13Reads++;

        $cpu = $runtime->context()->cpu();
        $pm = $cpu->isProtectedMode() ? 1 : 0;
        $pg = $cpu->isPagingEnabled() ? 1 : 0;
        $lm = $cpu->isLongMode() ? 1 : 0;
        $cs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte() & 0xFFFF;
        $linearIp = $runtime->memory()->offset() & 0xFFFFFFFF;

        $runtime->option()->logger()->warning(sprintf(
            'INT 13h %s: LBA=%d sectors=%d buf=0x%08X cdrom=%d PM=%d PG=%d LM=%d CS=0x%04X linearIP=0x%08X (%d/%d)',
            $kind,
            $lba,
            $sectorCount,
            $bufferAddress & 0xFFFFFFFF,
            $isCdrom ? 1 : 0,
            $pm,
            $pg,
            $lm,
            $cs,
            $linearIp,
            $this->traceInt13Reads,
            $limit,
        ));
    }

    /**
     * Stop execution when INT 13h reads a watched LBA.
     *
     * Set `PHPME_STOP_ON_INT13_READ_LBA` to a decimal/hex (0x...) number or a comma/space-separated list.
     * Example: `PHPME_STOP_ON_INT13_READ_LBA=1885` or `PHPME_STOP_ON_INT13_READ_LBA=1885,2974`.
     */
    private function stopOnInt13ReadLbaSet(RuntimeInterface $runtime): array
    {
        if ($this->stopOnInt13ReadLbaSet !== null) {
            return $this->stopOnInt13ReadLbaSet;
        }

        $this->stopOnInt13ReadLbaSet = $runtime->logicBoard()->debug()->trace()->stopOnInt13ReadLbaSet;
        return $this->stopOnInt13ReadLbaSet;
    }

    private function maybeStopOnInt13Read(RuntimeInterface $runtime, string $kind, int $lba, int $sectorCount, int $bufferAddress, bool $isCdrom, ?string $data = null): void
    {
        $set = $this->stopOnInt13ReadLbaSet($runtime);
        if ($set === [] || $sectorCount <= 0) {
            return;
        }

        $matchedLba = null;
        $matchedOffsetBytes = 0;
        for ($i = 0; $i < $sectorCount; $i++) {
            $candidate = $lba + $i;
            if (isset($set[$candidate])) {
                $matchedLba = $candidate;
                $matchedOffsetBytes = $i * ($isCdrom ? self::CD_SECTOR_SIZE : self::SECTOR_SIZE);
                break;
            }
        }
        if ($matchedLba === null) {
            return;
        }

        $preview = '';
        $memPreview = '';
        if ($data !== null && $data !== '') {
            $slice = substr($data, $matchedOffsetBytes, min(96, max(0, strlen($data) - $matchedOffsetBytes)));
            $preview = preg_replace('/[^\\x20-\\x7E]/', '.', $slice) ?? '';

            $memSlice = '';
            $base = ($bufferAddress + $matchedOffsetBytes) & 0xFFFFFFFF;
            $memLen = strlen($slice);
            for ($i = 0; $i < $memLen; $i++) {
                $memSlice .= chr($this->readMemory8($runtime, $base + $i));
            }
            $memPreview = preg_replace('/[^\\x20-\\x7E]/', '.', $memSlice) ?? '';
        }

        $runtime->option()->logger()->warning(sprintf(
            'INT 13h %s: stopping on LBA=%d (start=%d sectors=%d) buf=0x%08X cdrom=%d preview="%s" mem="%s"',
            $kind,
            $matchedLba,
            $lba,
            $sectorCount,
            $bufferAddress & 0xFFFFFFFF,
            $isCdrom ? 1 : 0,
            $preview,
            $memPreview,
        ));

        $executor = $runtime->architectureProvider()->instructionExecutor();
        if (method_exists($executor, 'getIpSampleReport')) {
            $report = $executor->getIpSampleReport(20);
            if (($report['every'] ?? 0) > 0 && ($report['samples'] ?? 0) > 0) {
                $runtime->option()->logger()->warning(sprintf(
                    'IP SAMPLE: every=%d insns=%d samples=%d unique=%d',
                    (int) ($report['every'] ?? 0),
                    (int) ($report['instructions'] ?? 0),
                    (int) ($report['samples'] ?? 0),
                    (int) ($report['unique'] ?? 0),
                ));

                foreach (($report['top'] ?? []) as $row) {
                    $ipVal = (int) ($row[0] ?? 0);
                    $hits = (int) ($row[1] ?? 0);
                    $runtime->option()->logger()->warning(sprintf(
                        'IP SAMPLE TOP: ip=0x%08X hits=%d',
                        $ipVal & 0xFFFFFFFF,
                        $hits,
                    ));
                }
            }
        } elseif (method_exists($executor, 'instructionCount')) {
            $runtime->option()->logger()->warning(sprintf(
                'INSNS: total=%d',
                (int) $executor->instructionCount(),
            ));
        }

        throw new HaltException('Stopped by PHPME_STOP_ON_INT13_READ_LBA');
    }

    private function reset(RuntimeInterface $runtime): void
    {
        // BIOS reset simply clears errors/carry.
        $runtime->memoryAccessor()->setCarryFlag(false);
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00);
    }

    private function getDriveParameters(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }

        // Use appropriate geometry based on drive type
        if ($dl < 0x80) {
            // 1.44MB floppy geometry
            $heads = self::FLOPPY_HEADS;
            $sectors = self::FLOPPY_SECTORS_PER_TRACK;
            $cylinders = self::FLOPPY_CYLINDERS;
        } else {
            // Hard disk geometry
            $heads = self::HEADS_PER_CYLINDER;
            $sectors = self::SECTORS_PER_TRACK;
            $cylinders = 1024;
        }

        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00); // AH = 0 (success)
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectors); // AL = sectors per track

        $cl = ($sectors & 0x3F) | ((($cylinders >> 8) & 0x03) << 6);
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::ECX, $cl);           // CL
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::ECX, $cylinders - 1);    // CH (max cylinder number)

        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EDX, $heads - 1);    // DH (max head number)
        $driveCount = $dl < 0x80 ? $this->floppyDriveCount($runtime) : $this->hardDriveCount($runtime);
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EDX, $driveCount & 0xFF);  // DL = number of drives

        $runtime->memoryAccessor()->setCarryFlag(false);
    }

    private function readSectorsCHS(RuntimeInterface $runtime, int $sectorsToRead): void
    {
        $runtime->option()->logger()->debug(sprintf(
            'INT 13h READ CHS request: sectors=%d DL=0x%02X ES:BX=%04X:%04X CS:IP=%04X:%04X',
            $sectorsToRead,
            $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit(),
            $runtime->memoryAccessor()->fetch(RegisterType::ES)->asByte(),
            $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize($runtime->context()->cpu()->addressSize()),
            $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(),
            $runtime->memory()->offset() & 0xFFFF
        ));

        $addressSize = $runtime->context()->cpu()->addressSize();
        $offsetMask = $addressSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize($addressSize) & $offsetMask;
        $es = $runtime->memoryAccessor()->fetch(RegisterType::ES)->asByte();

        $cx = $runtime->memoryAccessor()->fetch(RegisterType::ECX);
        $ch = $cx->asHighBit();  // cylinder low
        $cl = $cx->asLowBit(); // sector + cylinder high bits

        $dx = $runtime->memoryAccessor()->fetch(RegisterType::EDX);
        $dh = $dx->asHighBit();  // head
        $dl = $dx->asLowBit(); // drive

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01); // invalid command/drive
            return;
        }

        if ($sectorsToRead === 0) {
            $this->fail($runtime, 0x0D); // invalid number of sectors
            return;
        }

        // Read from bootStream (disk image) directly, not from unified memory
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if ($bootStream === null) {
            $this->fail($runtime, 0x20);
            return;
        }

        $cylinder = (($cl >> 6) & 0x03) << 8;
        $cylinder |= $ch;
        $sector = $cl & 0x3F;
        $head = $dh;

        if ($sector === 0) {
            $this->fail($runtime, 0x04);
            return;
        }

        // For floppy emulation (DL < 0x80), use 1.44MB floppy geometry
        if ($dl < 0x80) {
            $sectorsPerTrack = self::FLOPPY_SECTORS_PER_TRACK;
            $headsPerCylinder = self::FLOPPY_HEADS;
            $cylinders = self::FLOPPY_CYLINDERS;
        } else {
            $sectorsPerTrack = self::SECTORS_PER_TRACK;
            $headsPerCylinder = self::HEADS_PER_CYLINDER;
            $cylinders = 1024;
        }

        if ($head >= $headsPerCylinder || $sector > $sectorsPerTrack || $cylinder >= $cylinders) {
            $this->fail($runtime, 0x04);
            return;
        }

        $lba = ($cylinder * $headsPerCylinder + $head) * $sectorsPerTrack + ($sector - 1);
        $bytes = $sectorsToRead * self::SECTOR_SIZE;
        $bufferAddress = $this->segmentRegisterLinearAddress($runtime, RegisterType::ES, $bx, $addressSize);

        $runtime->logicBoard()->debug()->watchState()->notifyInt13Read($lba, $sectorsToRead);

        if ($this->shouldTraceInt13Caller($runtime) && ($lba === 67 || $lba === 1343)) {
            [$retCs, $retIp, $retFlags, $callerCs, $callerIp] = $this->peekChainedReturnFrame16($runtime);
            $runtime->option()->logger()->debug(sprintf(
                'INT 13h caller: return=%04X:%04X (callsite~%04X:%04X) FLAGS=0x%04X chainReturn=%04X:%04X',
                $retCs,
                $retIp,
                $retCs,
                ($retIp - 2) & 0xFFFF,
                $retFlags,
                $callerCs,
                $callerIp,
            ));
        }

        // Also debug the [0x01FA] value for MikeOS
        $bootLoadAddress = $runtime->logicBoard()->media()->primary()?->stream()?->loadAddress() ?? 0x7C00;
        $bufPtr = $this->readMemory16($runtime, $bootLoadAddress + 0x01FA);
        $runtime->option()->logger()->debug(sprintf(
            'INT 13h READ CHS: C=%d H=%d S=%d => LBA=%d, sectors=%d, ES:BX=%04X:%04X linear=0x%05X CS:IP=%04X:%04X',
            $cylinder, $head, $sector, $lba, $sectorsToRead, $es, $bx, $bufferAddress,
            $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(),
            $runtime->memory()->offset() & 0xFFFF
        ));

        $this->maybeTraceInt13Read($runtime, 'READ-CHS', $lba, $sectorsToRead, $bufferAddress, false);

        // Save bootStream offset and read from disk image
        $savedBootOffset = $bootStream->offset();

        try {
            $bootStream->setOffset($lba * self::SECTOR_SIZE);
        } catch (StreamReaderException) {
            $bootStream->setOffset($savedBootOffset);
            $this->fail($runtime, 0x20); // controller failure
            return;
        }

        $debugBytes = [];
        try {
            $data = $bootStream->read($bytes);
        } catch (StreamReaderException) {
            $bootStream->setOffset($savedBootOffset);
            $this->fail($runtime, 0x20);
            return;
        }

        if (strlen($data) !== $bytes) {
            $bootStream->setOffset($savedBootOffset);
            $this->fail($runtime, 0x20);
            return;
        }

        $this->writeBlockToMemory($runtime, $bufferAddress, $data);

        // Debug first 16 bytes
        $first = substr($data, 0, min(16, $bytes));
        $firstLen = strlen($first);
        for ($i = 0; $i < $firstLen; $i++) {
            $debugBytes[] = sprintf('%02X', ord($first[$i]));
        }

        if (!empty($debugBytes)) {
            $runtime->option()->logger()->debug(sprintf(
                'INT 13h READ CHS: first 16 bytes at 0x%05X: %s',
                $bufferAddress,
                implode(' ', $debugBytes)
            ));
        }

        // Restore bootStream offset
        $bootStream->setOffset($savedBootOffset);

        // Invalidate decode/translation caches only when we overwrote a page that has already been executed.
        // Most INT 13h reads load data into scratch buffers; flushing all caches every time is very expensive
        // and unnecessary (it defeats translation block caching).
        $runtime->architectureProvider()
            ->instructionExecutor()
            ->invalidateCachesIfExecutedPageOverlaps($bufferAddress, $bytes);

        // update AL with sectors read, AH = 0, clear CF
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00);
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToRead);
        $runtime->memoryAccessor()->setCarryFlag(false);

        // Track mapping for later addressMap lookups (best-effort)
        $runtime->addressMap()->register(
            max(0, $bufferAddress - 1),
            new HardDisk($dl, $lba * self::SECTOR_SIZE, $lba * self::SECTOR_SIZE),
        );
    }

    private function extensionsPresent(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        // EDD version 3.0, features: bit0 (extended disk access), bit1 (EDD)
        $ma->writeToHighBit(RegisterType::EAX, 0x30); // AH = version
        $ma->write16Bit(RegisterType::EBX, 0xAA55);
        $ma->write16Bit(RegisterType::ECX, 0x0003);
        $ma->setCarryFlag(false);
    }

    private function readSectorsLBA(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);

        // DAP is specified via DS:SI in the BIOS API. Use the selector to compute a linear address.
        $dapLinear = $this->segmentRegisterLinearAddress($runtime, RegisterType::DS, $si, $addressSize);

        // Read DAP size using byte-addressable memory. Some bootloaders treat SI
        // as a linear pointer, so if DS:SI looks invalid, fall back to raw SI.
        $size = $this->readMemory8($runtime, $dapLinear);
        if ($size < 16) {
            $fallbackLinear = $si;
            $sizeAlt = $this->readMemory8($runtime, $fallbackLinear);
            if ($sizeAlt >= 16) {
                $runtime->option()->logger()->debug(sprintf(
                    'INT 13h READ LBA: DS:SI DAP invalid (size=%d), falling back to linear SI=0x%05X (size=%d)',
                    $size,
                    $fallbackLinear,
                    $sizeAlt
                ));
                $dapLinear = $fallbackLinear;
                $size = $sizeAlt;
            }
        }

        if ($size < 16) {
            $runtime->option()->logger()->error(sprintf(
                'INT 13h READ LBA failed: DAP size too small (size=%d at linear 0x%05X)',
                $size,
                $dapLinear
            ));
            $this->fail($runtime, 0x01);
            return;
        }

        // Read from bootStream (disk image) directly
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if ($bootStream === null) {
            $this->fail($runtime, 0x20);
            return;
        }

        // Check if this is a No Emulation CD-ROM boot
        $isNoEmulationCdrom = ($bootStream instanceof ISOBootImageStream) && $bootStream->isNoEmulation();

        $linearMask = $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;

        $sectorCount = $this->readMemory16($runtime, $dapLinear + 2);
        $bufferAddress = 0;
        $lba = 0;

        // DAP variants:
        // - size=0x10: buffer is a real-mode segment:offset at +4/+6, LBA at +8
        // - size>=0x18: several layouts exist in the wild:
        //   A) buffer is 32-bit linear/physical at +4, LBA (64-bit) at +8
        //   B) buffer is 64-bit physical at +4, LBA (64-bit) at +12
        if ($size >= 0x18) {
            $bufferLow = $this->readMemory32($runtime, $dapLinear + 4);
            $d8 = $this->readMemory32($runtime, $dapLinear + 8);
            $d12 = $this->readMemory32($runtime, $dapLinear + 12);
            $d16 = $this->readMemory32($runtime, $dapLinear + 16);

            // Both layouts share the low 32-bit buffer address at +4 for <=4GB buffers.
            $bufferAddress = $bufferLow & $linearMask;

            // Layout A: LBA at +8 (low) / +12 (high)
            $lbaLowA = $d8;
            $lbaHighA = $d12;

            // Layout B: buffer high at +8, LBA at +12 (low) / +16 (high)
            $bufferHighB = $d8;
            $lbaLowB = $d12;
            $lbaHighB = $d16;

            // For CD-ROM reads we can validate LBA against the ISO size; for other media
            // streams, the backing size may not reflect all readable LBAs (test streams),
            // so avoid rejecting candidates based on size.
            if ($isNoEmulationCdrom && $bootStream instanceof ISOBootImageStream) {
                $maxLba = (int) max(0, intdiv($bootStream->iso()->fileSize(), self::CD_SECTOR_SIZE) - 1);
            } else {
                $maxLba = PHP_INT_MAX;
            }

            $aOk = $lbaHighA === 0 && $lbaLowA <= $maxLba;
            $bOk = $bufferHighB === 0 && $lbaHighB === 0 && $lbaLowB <= $maxLba;

            // Prefer the layout that yields a plausible (<4GB) buffer + LBA.
            // GRUB commonly uses layout B while some bootloaders use layout A.
            $useB = false;
            if ($bOk && !$aOk) {
                $useB = true;
            } elseif ($aOk && !$bOk) {
                $useB = false;
            } elseif ($aOk && $bOk) {
                // Both map to LBA=0: keep legacy behaviour.
                $useB = $lbaLowB !== 0 && $lbaLowA === 0;
            } else {
                // Fallback: if layout B's 64-bit assumptions hold, prefer it.
                $useB = $bufferHighB === 0 && $lbaHighB === 0 && $lbaLowB <= $maxLba;
            }

            $lba = $useB ? $lbaLowB : $lbaLowA;
        } else {
            $bufferOffset = $this->readMemory16($runtime, $dapLinear + 4);
            $bufferSegment = $this->readMemory16($runtime, $dapLinear + 6);
            $lba = $this->readMemory32($runtime, $dapLinear + 8); // lower 32 bits

            // Buffer pointer in the DAP is a real-mode segment:offset pair (not a selector),
            // even when the caller is running with 32-bit registers.
            $bufferAddress = $this->realModeSegmentOffsetLinearAddress($runtime, $bufferSegment, $bufferOffset);
        }

        // Sanity check: sector count should be reasonable (max 127 sectors is standard for BIOS)
        // Also check if DAP looks corrupted by verifying sectorCount is reasonable
        if ($sectorCount === 0 || $sectorCount > 127) {
            $runtime->option()->logger()->error(sprintf(
                'INT 13h READ LBA failed: invalid sectorCount=%d (DAP at 0x%05X)',
                $sectorCount,
                $dapLinear
            ));
            $this->fail($runtime, 0x01); // Invalid function/parameter
            return;
        }

        $runtime->option()->logger()->debug(sprintf(
            'INT 13h READ LBA: DS:SI=%04X:%04X DAP=0x%02X LBA=%d sectors=%d => linear=0x%05X (CD-ROM=%s)',
            $ds,
            $si,
            $size,
            $lba,
            $sectorCount,
            $bufferAddress,
            $isNoEmulationCdrom ? 'yes' : 'no'
        ));

        $runtime->logicBoard()->debug()->watchState()->notifyInt13Read($lba, $sectorCount);

        $this->maybeTraceInt13Read($runtime, 'READ-LBA', $lba, $sectorCount, $bufferAddress, $isNoEmulationCdrom);

        if ($isNoEmulationCdrom) {
            // For No Emulation CD-ROM, read directly from ISO using 2048-byte sectors
            $data = $bootStream->readIsoSectors($lba, $sectorCount);
            if ($data === null) {
                $runtime->option()->logger()->error('INT 13h READ LBA failed: ISO read error');
                $this->fail($runtime, 0x20);
                return;
            }

            $data = $this->maybePatchBootConfigData($runtime, $data);

            // Write data to memory - ISOLINUX manages its own memory layout
            // and uses INT 13h to load additional sectors to specific addresses
            $this->writeBlockToMemory($runtime, $bufferAddress, $data);

            // Invalidate caches only when we overwrote a page that has already been executed.
            $runtime->architectureProvider()
                ->instructionExecutor()
                ->invalidateCachesIfExecutedPageOverlaps($bufferAddress, strlen($data));

            $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00);
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
            $runtime->memoryAccessor()->setCarryFlag(false);

            // Track mapping for later addressMap lookups
            $runtime->addressMap()->register(
                max(0, $bufferAddress - 1),
                new HardDisk(0x80, $lba * self::CD_SECTOR_SIZE, $lba * self::CD_SECTOR_SIZE),
            );

            $this->maybeStopOnInt13Read($runtime, 'READ-LBA', $lba, $sectorCount, $bufferAddress, true, $data);
            return;
        }

        // Standard disk - use 512-byte sectors
        $bytes = $sectorCount * self::SECTOR_SIZE;

        // Save and restore bootStream offset
        $savedBootOffset = $bootStream->offset();

        try {
            $bootStream->setOffset($lba * self::SECTOR_SIZE);
        } catch (StreamReaderException) {
            $bootStream->setOffset($savedBootOffset);
            $runtime->option()->logger()->error('INT 13h READ LBA failed: seek error');
            $this->fail($runtime, 0x20);
            return;
        }

        try {
            $data = $bootStream->read($bytes);
        } catch (StreamReaderException) {
            $bootStream->setOffset($savedBootOffset);
            $runtime->option()->logger()->error('INT 13h READ LBA failed: read error');
            $this->fail($runtime, 0x20);
            return;
        }

        if (strlen($data) !== $bytes) {
            $bootStream->setOffset($savedBootOffset);
            $runtime->option()->logger()->error('INT 13h READ LBA failed: short read');
            $this->fail($runtime, 0x20);
            return;
        }

        $this->writeBlockToMemory($runtime, $bufferAddress, $data);

        $bootStream->setOffset($savedBootOffset);

        // Invalidate caches only when we overwrote a page that has already been executed.
        $runtime->architectureProvider()
            ->instructionExecutor()
            ->invalidateCachesIfExecutedPageOverlaps($bufferAddress, strlen($data));

        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00);
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
        $runtime->memoryAccessor()->setCarryFlag(false);

        // Track mapping for later addressMap lookups (best-effort)
        $runtime->addressMap()->register(
            max(0, $bufferAddress - 1),
            new HardDisk(0x80, $lba * self::SECTOR_SIZE, $lba * self::SECTOR_SIZE),
        );

        $this->maybeStopOnInt13Read($runtime, 'READ-LBA', $lba, $sectorCount, $bufferAddress, false, $data);
    }

    private function writeBlockToMemory(RuntimeInterface $runtime, int $address, string $data): void
    {
        if ($data === '') {
            return;
        }

        $memory = $runtime->memory();
        if ($memory instanceof \PHPMachineEmulator\Stream\RustMemoryStream) {
            $memory->copyFromString($data, $address);
            return;
        }

        $len = strlen($data);
        $runtime->memoryAccessor()->allocate($address, $len, safe: false);
        for ($i = 0; $i < $len; $i++) {
            $runtime->memoryAccessor()->writeRawByte($address + $i, ord($data[$i]));
        }
    }

    private function getDriveParametersExtended(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);
        $buffer = $this->segmentRegisterLinearAddress($runtime, RegisterType::DS, $si, $addressSize);

        // Decide logical sector size based on media type.
        // El Torito no-emulation CD boots should expose 2048-byte logical sectors.
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        $isCdRom = ($bootStream instanceof ISOBootImageStream) && $bootStream->isNoEmulation();

        $bytesPerSector = $isCdRom ? self::CD_SECTOR_SIZE : self::SECTOR_SIZE;

        // EDD "Extended Drive Parameters" (INT 13h AH=48h) buffer layout:
        // 00h WORD: buffer size (bytes) (input)
        // 02h WORD: info flags
        // 04h DWORD: cylinders
        // 08h DWORD: heads
        // 0Ch DWORD: sectors per track
        // 10h QWORD: total sectors
        // 18h WORD: bytes per sector
        // 1Ah DWORD: EDD configuration parameters (segment:offset pointer) (optional)
        //
        // GRUB relies on bytes-per-sector being at offset 0x18 (24).
        $ma = $runtime->memoryAccessor();
        $requestedSize = $this->readMemory16($runtime, $buffer);
        if ($requestedSize < 0x1A) {
            $requestedSize = 0x1A;
        }

        // Keep the write bounded to a reasonable size (avoid runaway if caller passes garbage).
        $writeSize = min($requestedSize, 0x80);

        $ma->allocate($buffer, $writeSize, safe: false);
        for ($i = 0; $i < $writeSize; $i++) {
            $ma->writeRawByte($buffer + $i, 0);
        }

        $cylinders = 1024;
        $heads = self::HEADS_PER_CYLINDER;
        $sectors = self::SECTORS_PER_TRACK;

        // Estimate total sector count. For CD-ROM, use the ISO size; otherwise keep a sane default.
        if ($isCdRom) {
            $isoSize = $bootStream->iso()->fileSize();
            $totalSectors = (int) max(1, intdiv($isoSize, $bytesPerSector));
        } else {
            $totalSectors = 0x0010_0000; // ~512MB worth
        }

        $ma->write16Bit($buffer, $requestedSize);
        $ma->write16Bit($buffer + 2, 0x0001); // flags: CHS info valid
        $ma->writeBySize($buffer + 4, $cylinders, 32);
        $ma->writeBySize($buffer + 8, $heads, 32);
        $ma->writeBySize($buffer + 12, $sectors, 32);

        $ma->writeBySize($buffer + 16, $totalSectors & 0xFFFFFFFF, 32);
        $ma->writeBySize($buffer + 20, ($totalSectors >> 32) & 0xFFFFFFFF, 32);

        $ma->write16Bit($buffer + 24, $bytesPerSector);

        // Optional EDD config parameters pointer (segment:offset). Not provided.
        if ($requestedSize >= 0x1E) {
            $ma->writeBySize($buffer + 26, 0, 32);
        }

        $ma->writeToHighBit(RegisterType::EAX, 0x00);
        $ma->setCarryFlag(false);
    }

    private function segmentRegisterLinearAddress(RuntimeInterface $runtime, RegisterType $segment, int $offset, int $addressSize): int
    {
        $offsetMask = $addressSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $linearMask = $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;

        $selector = $runtime->memoryAccessor()->fetch($segment)->asByte() & 0xFFFF;
        $effOffset = $offset & $offsetMask;

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $segBase = $this->selectorBaseAddress($runtime, $selector);
            if ($segBase !== null) {
                return ($segBase + $effOffset) & $linearMask;
            }
            return $effOffset & $linearMask;
        }

        // Big Real Mode (Unreal Mode) support: if we have a cached descriptor, use its base.
        $cached = $runtime->context()->cpu()->getCachedSegmentDescriptor($segment);
        $segBase = $cached['base'] ?? (($selector << 4) & 0xFFFFF);

        return ($segBase + $effOffset) & $linearMask;
    }

    private function realModeSegmentOffsetLinearAddress(RuntimeInterface $runtime, int $segment, int $offset): int
    {
        $linearMask = $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        return (((($segment & 0xFFFF) << 4) + ($offset & 0xFFFF)) & $linearMask);
    }

    private function selectorBaseAddress(RuntimeInterface $runtime, int $selector): ?int
    {
        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $runtime->context()->cpu()->ldtr();
            if (($ldtr['selector'] ?? 0) === 0) {
                return null;
            }
            $tableBase = $ldtr['base'] ?? 0;
            $tableLimit = $ldtr['limit'] ?? 0;
        } else {
            $gdtr = $runtime->context()->cpu()->gdtr();
            $tableBase = $gdtr['base'] ?? 0;
            $tableLimit = $gdtr['limit'] ?? 0;
        }

        $index = ($selector >> 3) & 0x1FFF;
        $descAddr = $tableBase + ($index * 8);
        if ($descAddr + 7 > $tableBase + $tableLimit) {
            return null;
        }

        $b2 = $this->readMemory8($runtime, $descAddr + 2);
        $b3 = $this->readMemory8($runtime, $descAddr + 3);
        $b4 = $this->readMemory8($runtime, $descAddr + 4);
        $b7 = $this->readMemory8($runtime, $descAddr + 7);

        return (($b2) | ($b3 << 8) | ($b4 << 16) | ($b7 << 24)) & 0xFFFFFFFF;
    }

    private function writeSectorsCHS(RuntimeInterface $runtime, int $sectorsToWrite): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $offsetMask = $addressSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize($addressSize) & $offsetMask;
        $es = $runtime->memoryAccessor()->fetch(RegisterType::ES)->asByte();

        $cx = $runtime->memoryAccessor()->fetch(RegisterType::ECX);
        $ch = $cx->asHighBit();
        $cl = $cx->asLowBit();

        $dx = $runtime->memoryAccessor()->fetch(RegisterType::EDX);
        $dh = $dx->asHighBit();
        $dl = $dx->asLowBit();

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }

        if ($sectorsToWrite === 0) {
            $this->fail($runtime, 0x0D);
            return;
        }

        $cylinder = (($cl >> 6) & 0x03) << 8;
        $cylinder |= $ch;
        $sector = $cl & 0x3F;
        $head = $dh;

        if ($sector === 0) {
            $this->fail($runtime, 0x04);
            return;
        }

        // Use appropriate geometry based on drive type
        if ($dl < 0x80) {
            $sectorsPerTrack = self::FLOPPY_SECTORS_PER_TRACK;
            $headsPerCylinder = self::FLOPPY_HEADS;
            $cylinders = self::FLOPPY_CYLINDERS;
        } else {
            $sectorsPerTrack = self::SECTORS_PER_TRACK;
            $headsPerCylinder = self::HEADS_PER_CYLINDER;
            $cylinders = 1024;
        }

        if ($head >= $headsPerCylinder || $sector > $sectorsPerTrack || $cylinder >= $cylinders) {
            $this->fail($runtime, 0x04);
            return;
        }

        $lba = ($cylinder * $headsPerCylinder + $head) * $sectorsPerTrack + ($sector - 1);
        // Calculate buffer address for completeness (write is a no-op).
        $this->segmentRegisterLinearAddress($runtime, RegisterType::ES, $bx, $addressSize);

        // Write is a no-op for read-only media, but we accept the data
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00);
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToWrite);
        $runtime->memoryAccessor()->setCarryFlag(false);
    }

    private function writeSectorsLBA(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);
        $dapLinear = $this->segmentRegisterLinearAddress($runtime, RegisterType::DS, $si, $addressSize);

        $size = $this->readMemory8($runtime, $dapLinear);
        if ($size < 16) {
            $this->fail($runtime, 0x01);
            return;
        }

        $sectorCount = $this->readMemory16($runtime, $dapLinear + 2);

        if ($sectorCount === 0) {
            $this->fail($runtime, 0x04);
            return;
        }

        // Write is a no-op for read-only media, but we accept the data
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00);
        $runtime->memoryAccessor()->setCarryFlag(false);
    }

    private function getDiskType(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $ma = $runtime->memoryAccessor();

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            // No such drive.
            $ma->writeToHighBit(RegisterType::EAX, 0x00);
            $ma->setCarryFlag(false);
            return;
        }

        if ($dl >= 0x80) {
            // Hard disk - return type 3
            $ma->writeToHighBit(RegisterType::EAX, 0x03);
            // CX:DX = number of 512-byte sectors
            $totalSectors = 0x0010_0000; // ~512MB
            $ma->write16Bit(RegisterType::ECX, ($totalSectors >> 16) & 0xFFFF);
            $ma->write16Bit(RegisterType::EDX, $totalSectors & 0xFFFF);
        } else {
            // Floppy drive with change-line support (typical 1.44MB).
            $ma->writeToHighBit(RegisterType::EAX, 0x02);
        }

        $ma->setCarryFlag(false);
    }

    private function floppyDriveCount(RuntimeInterface $runtime): int
    {
        $equipFlags = $this->readMemory16($runtime, 0x410);
        if (($equipFlags & 0x1) === 0) {
            return 0;
        }

        return ((($equipFlags >> 6) & 0x3) + 1);
    }

    private function hardDriveCount(RuntimeInterface $runtime): int
    {
        return $this->readMemory8($runtime, 0x475) & 0xFF;
    }

    private function isValidBiosDrive(RuntimeInterface $runtime, int $dl): bool
    {
        // El Torito no-emulation boots typically identify the CD-ROM as 0xE0-0xEF.
        // Treat these as valid "drives" when we're booted from a no-emulation ISO.
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if ($bootStream instanceof ISOBootImageStream && $bootStream->isNoEmulation()) {
            if ($dl >= 0xE0 && $dl <= 0xEF) {
                return true;
            }
        }

        if ($dl < 0x80) {
            $count = $this->floppyDriveCount($runtime);
            if ($dl < $count) {
                return true;
            }

            // El Torito floppy emulation can appear as a non-standard "virtual" drive number
            // even though the BDA equipment flags only represent up to 4 physical floppies.
            return $dl === self::ELTORITO_EMULATED_FLOPPY_DRIVE && $this->isElToritoFloppyEmulation($runtime);
        }

        $count = $this->hardDriveCount($runtime);
        return ($dl - 0x80) < $count;
    }

    private function isElToritoFloppyEmulation(RuntimeInterface $runtime): bool
    {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if (!$bootStream instanceof ISOBootImageStream) {
            return false;
        }
        if ($bootStream->isNoEmulation()) {
            return false;
        }

        $mediaType = $bootStream->bootImage()->mediaType();
        return in_array($mediaType, [
            ElTorito::MEDIA_FLOPPY_1_2M,
            ElTorito::MEDIA_FLOPPY_1_44M,
            ElTorito::MEDIA_FLOPPY_2_88M,
        ], true);
    }

    /**
     * Handle El Torito boot info/termination (INT 13h AH=4Bh).
     *
     * We implement AL=01h (Get Boot Info) which isolinux relies on, and fall back
     * to success for termination requests.
     */
    private function handleBootInfo(RuntimeInterface $runtime, int $al): void
    {
        $ma = $runtime->memoryAccessor();
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();

        // AL=01h: Get Boot Info (El Torito)
        if ($al === 0x01 && $bootStream instanceof ISOBootImageStream) {
            $addressSize = $runtime->context()->cpu()->addressSize();
            $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
            $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);
            $buffer = $this->segmentRegisterLinearAddress($runtime, RegisterType::DS, $si, $addressSize);

            $bootImage = $bootStream->bootImage();

            // Packet layout (19 bytes):
            // 0: size (0x13)
            // 1: media type
            // 2: drive number
            // 3: controller number (0)
            // 4-7: boot image start LBA
            // 8-9: device spec packet segment (0 for none)
            // 10-11: device spec packet offset (0)
            // 12-13: load segment
            // 14-15: sector count (512-byte virtual sectors)
            // 16-18: reserved
            $ma->allocate($buffer, 0x13, safe: false);
            $runtime->memoryAccessor()->writeRawByte($buffer + 0, 0x13);
            $runtime->memoryAccessor()->writeRawByte($buffer + 1, $bootImage->mediaType());
            $runtime->memoryAccessor()->writeRawByte($buffer + 2, $dl);
            $runtime->memoryAccessor()->writeRawByte($buffer + 3, 0x00);
            $runtime->memoryAccessor()->writeRawByte($buffer + 16, 0x00);
            $runtime->memoryAccessor()->writeRawByte($buffer + 17, 0x00);
            $runtime->memoryAccessor()->writeRawByte($buffer + 18, 0x00);

            // Little-endian helpers
            $writeWord = function (int $addr, int $value) use ($runtime): void {
                $runtime->memoryAccessor()->writeRawByte($addr, $value & 0xFF);
                $runtime->memoryAccessor()->writeRawByte($addr + 1, ($value >> 8) & 0xFF);
            };
            $writeDword = function (int $addr, int $value) use ($writeWord): void {
                $writeWord($addr, $value & 0xFFFF);
                $writeWord($addr + 2, ($value >> 16) & 0xFFFF);
            };

            // Spec expects the boot catalog LBA here; fall back to boot image RBA.
            $catalogLba = $bootStream->iso()->bootRecord()?->bootCatalogSector ?? $bootImage->loadRBA();
            $writeDword($buffer + 4, $bootImage->loadRBA());
            $writeWord($buffer + 8, 0x0000);  // device spec packet segment (none)
            $writeWord($buffer + 10, 0x0000); // device spec packet offset (none)
            $writeWord($buffer + 12, $bootImage->loadSegment());
            $writeWord($buffer + 14, $bootImage->catalogSectorCount());

            $runtime->option()->logger()->debug(sprintf(
                'INT 13h GET BOOT INFO: DL=0x%02X media=0x%02X LBA=%d loadSeg=0x%04X sectors=%d buffer=0x%05X',
                $dl,
                $bootImage->mediaType(),
                $catalogLba,
                $bootImage->loadSegment(),
                $bootImage->catalogSectorCount(),
                $buffer
            ));

            $ma->writeToHighBit(RegisterType::EAX, 0x00);
            $ma->setCarryFlag(false);
            return;
        }

        // For termination or unsupported variants, simply report success.
        $ma->writeToHighBit(RegisterType::EAX, 0x00);
        $ma->setCarryFlag(false);
    }

    private function unsupported(RuntimeInterface $runtime, int $command): void
    {
        $runtime->option()->logger()->error(sprintf('Disk interrupt command 0x%02X not supported yet', $command));
        $this->fail($runtime, 0x01);
    }

    private function fail(RuntimeInterface $runtime, int $status): void
    {
        $runtime->option()->logger()->error(sprintf('INT 13h failed with status 0x%02X', $status));
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, $status);
        $runtime->memoryAccessor()->setCarryFlag(true);
    }

    /**
     * Read a single byte from memory (8-bit read).
     * Uses readRawByte for byte-addressable memory.
     */
    private function readMemory8(RuntimeInterface $runtime, int $address): int
    {
        $value = $runtime->memoryAccessor()->readRawByte($address);
        if ($value !== null) {
            return $value;
        }

        // In unified memory model, read directly using linear address
        $memory = $runtime->memory();
        $currentOffset = $memory->offset();
        try {
            $memory->setOffset($address);
            $byte = $memory->byte();
            $memory->setOffset($currentOffset);
            return $byte;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Read 16-bit value from memory (little-endian).
     * Combines two consecutive 8-bit reads.
     */
    private function readMemory16(RuntimeInterface $runtime, int $address): int
    {
        $lo = $this->readMemory8($runtime, $address);
        $hi = $this->readMemory8($runtime, $address + 1);
        return ($hi << 8) | $lo;
    }

    /**
     * Read 32-bit value from memory (little-endian).
     * Combines two consecutive 16-bit reads.
     */
    private function readMemory32(RuntimeInterface $runtime, int $address): int
    {
        $lo = $this->readMemory16($runtime, $address);
        $hi = $this->readMemory16($runtime, $address + 2);
        return ($hi << 16) | $lo;
    }

    private function shouldTraceInt13Caller(RuntimeInterface $runtime): bool
    {
        if ($this->traceInt13Caller === null) {
            $this->traceInt13Caller = $runtime->logicBoard()->debug()->trace()->traceInt13Caller;
        }
        return $this->traceInt13Caller;
    }

    /**
     * Peek the stack frame expected by an INT handler (or PUSHF+CALL FAR chain):
     * [SP+0]=IP, [SP+2]=CS, [SP+4]=FLAGS (all 16-bit).
     *
     * Also attempt to read the next FAR return address at [SP+6]=IP, [SP+8]=CS.
     *
     * @return array{int,int,int,int,int} [CS, IP, FLAGS, chainCS, chainIP]
     */
    private function peekChainedReturnFrame16(RuntimeInterface $runtime): array
    {
        $ma = $runtime->memoryAccessor();
        $ss = $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF;
        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize(16) & 0xFFFF;
        $linearMask = $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        $frameLinear = ((($ss << 4) & 0xFFFFF) + $sp) & $linearMask;

        $ip = $this->readMemory16($runtime, $frameLinear);
        $cs = $this->readMemory16($runtime, ($frameLinear + 2) & $linearMask);
        $flags = $this->readMemory16($runtime, ($frameLinear + 4) & $linearMask);

        // Best-effort: if called via a FAR CALL (common in DOS/IO.SYS),
        // the caller return CS:IP sits right under the FLAGS we pushed.
        $chainIp = $this->readMemory16($runtime, ($frameLinear + 6) & $linearMask);
        $chainCs = $this->readMemory16($runtime, ($frameLinear + 8) & $linearMask);

        return [$cs, $ip, $flags, $chainCs, $chainIp];
    }

    private function bootConfigPatcher(): BootConfigPatcher
    {
        if ($this->bootConfigPatcher === null) {
            $this->bootConfigPatcher = new BootConfigPatcher(
                $this->runtime->logicBoard()->debug()->bootConfig(),
            );
        }
        return $this->bootConfigPatcher;
    }

    private function maybePatchBootConfigData(RuntimeInterface $runtime, string $data): string
    {
        $result = $this->bootConfigPatcher()->patch($data);
        if ($result->isPatched() && !$this->bootConfigPatchAnnounced) {
            $this->bootConfigPatchAnnounced = true;
            $runtime->option()->logger()->warning(sprintf(
                'PATCH: boot config updated (%s)',
                implode(', ', $result->appliedRules),
            ));
        }
        return $result->data;
    }
}
