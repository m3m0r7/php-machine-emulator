<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\BootConfig\BootConfigPatcher;
use PHPMachineEmulator\Disk\HardDisk;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\Intel\Service\VideoMemoryService;
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
    private const EXTSTART_HD = 0x80;
    private const EXTSTART_CD = 0xE0;
    private const BDA_FLOPPY_LAST_STATUS = 0x441;
    private const BDA_DISK_LAST_STATUS = 0x474;
    private const BDA_DISK_INTERRUPT_FLAG = 0x48E;
    private ?bool $traceInt13Caller = null;
    private ?int $traceInt13ReadsLimit = null;
    private int $traceInt13Reads = 0;
    /** @var array<int,true>|null */
    private ?array $stopOnInt13ReadLbaSet = null;
    private ?BootConfigPatcher $bootConfigPatcher = null;
    private bool $bootConfigPatchAnnounced = false;
    private bool $bootImagePatched = false;
    /** @var array<int,int> */
    private array $cdromLocks = [];

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

        $runtime->memoryAccessor()->writeBySize(self::BDA_DISK_INTERRUPT_FLAG, 0x00, 8);

        match ($ah) {
            0x00 => $this->reset($runtime),
            0x01 => $this->readStatus($runtime),
            0x02 => $this->readSectorsCHS($runtime, $al),
            0x03 => $this->writeSectorsCHS($runtime, $al),
            0x04 => $this->verifySectorsCHS($runtime, $al),
            0x05 => $this->formatTrack($runtime, $al),
            0x41 => $this->extensionsPresent($runtime),
            0x42 => $this->readSectorsLBA($runtime),
            0x43 => $this->writeSectorsLBA($runtime),
            0x44 => $this->verifySectorsLBA($runtime),
            0x45 => $this->lockUnlock($runtime),
            0x46 => $this->eject($runtime),
            0x47 => $this->seekExtended($runtime),
            0x48 => $this->getDriveParametersExtended($runtime),
            0x08 => $this->getDriveParameters($runtime),
            0x09 => $this->initDriveParameters($runtime),
            0x0C => $this->seek($runtime),
            0x0D => $this->alternateReset($runtime),
            0x10 => $this->checkDriveReady($runtime),
            0x11 => $this->recalibrate($runtime),
            0x14 => $this->controllerDiagnostic($runtime),
            0x15 => $this->getDiskType($runtime),
            0x16 => $this->detectChange($runtime),
            0x18 => $this->setMediaTypeForFormat($runtime),
            0x4B => $this->handleBootInfo($runtime, $al),
            0x4E => $this->setHardwareConfig($runtime),
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
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $linearIp = $runtime->memory()->offset() & 0xFFFFFFFF;

        $runtime->option()->logger()->warning(sprintf(
            'INT 13h %s: LBA=%d sectors=%d DL=0x%02X buf=0x%08X cdrom=%d PM=%d PG=%d LM=%d CS=0x%04X linearIP=0x%08X (%d/%d)',
            $kind,
            $lba,
            $sectorCount,
            $dl,
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

    private function bootConfigPatcher(): BootConfigPatcher
    {
        if ($this->bootConfigPatcher === null) {
            $this->bootConfigPatcher = new BootConfigPatcher(
                $this->runtime->logicBoard()->debug()->bootConfig(),
            );
        }
        return $this->bootConfigPatcher;
    }

    private function maybePatchBootImage(RuntimeInterface $runtime, ISOBootImageStream $bootStream): void
    {
        if ($this->bootImagePatched) {
            return;
        }

        if ($bootStream->isNoEmulation()) {
            $this->bootImagePatched = true;
            return;
        }

        $bootImage = $bootStream->bootImage();
        if ($bootImage->getFileIndex() === []) {
            $this->bootImagePatched = true;
            return;
        }

        $applied = [];
        foreach (['CONFIG.SYS', 'AUTOEXEC.BAT'] as $filename) {
            $info = $bootImage->getFileInfo($filename);
            if ($info === null || $info['size'] <= 0) {
                continue;
            }

            $original = $bootImage->readAt($info['offset'], $info['size']);
            $result = $this->bootConfigPatcher()->patch($original);
            if (!$result->isPatched()) {
                continue;
            }

            $bootStream->replaceRange($info['offset'], $result->data);
            foreach ($result->appliedRules as $rule) {
                $applied[$rule] = true;
            }
        }

        if ($runtime->logicBoard()->debug()->bootConfig()->disableDosCdromDrivers) {
            $bootSector = $bootImage->data();
            if (strlen($bootSector) >= 36) {
                $bytesPerSector = $this->readUint16LE($bootSector, 11) ?: self::SECTOR_SIZE;
                $reserved = $this->readUint16LE($bootSector, 14);
                $fats = ord($bootSector[16] ?? "\x00");
                $rootEntries = $this->readUint16LE($bootSector, 17);
                $sectorsPerFat = $this->readUint16LE($bootSector, 22);

                if ($bytesPerSector > 0 && $rootEntries > 0 && $sectorsPerFat > 0) {
                    $rootOffset = ($reserved + $fats * $sectorsPerFat) * $bytesPerSector;
                    $rootBytes = $rootEntries * 32;
                    $rootData = $bootImage->readAt($rootOffset, $rootBytes);
                    $patchedRoot = $rootData;
                    $entryCount = intdiv(strlen($patchedRoot), 32);
                    for ($i = 0; $i < $entryCount; $i++) {
                        $entryOffset = $i * 32;
                        $name = substr($patchedRoot, $entryOffset, 11);
                        if (!in_array($name, ['CD1     SYS', 'MSCDEX  EXE'], true)) {
                            continue;
                        }
                        $patchedRoot[$entryOffset] = "\xE5";
                        $patchedRoot = substr_replace($patchedRoot, "\x00\x00", $entryOffset + 26, 2);
                        $patchedRoot = substr_replace($patchedRoot, "\x00\x00\x00\x00", $entryOffset + 28, 4);
                        if ($name === 'MSCDEX  EXE') {
                            $applied['dos_mscdex'] = true;
                        } else {
                            $applied['dos_cd_sys'] = true;
                        }
                    }

                    if ($patchedRoot !== $rootData) {
                        $bootStream->replaceRange($rootOffset, $patchedRoot);
                    }
                }
            }

            foreach ([
                ['CD1.SYS', 'CD1', 'dos_cd_sys'],
                ['MSCDEX.EXE', 'MSCDEX', 'dos_mscdex'],
            ] as [$filename, $deviceName, $rule]) {
                $info = $bootImage->getFileInfo($filename);
                if ($info === null || $info['size'] <= 0) {
                    continue;
                }
                $stub = $this->buildDosDriverStub($deviceName);
                if (strlen($stub) > $info['size']) {
                    continue;
                }
                $fill = $stub . str_repeat("\x00", $info['size'] - strlen($stub));
                $bootStream->replaceRange($info['offset'], $fill);
                $applied[$rule] = true;
            }
        }

        if ($applied !== [] && !$this->bootConfigPatchAnnounced) {
            $this->bootConfigPatchAnnounced = true;
            $runtime->option()->logger()->warning(sprintf(
                'PATCH: boot config updated (%s)',
                implode(', ', array_keys($applied)),
            ));
        }

        $this->bootImagePatched = true;
    }

    private function maybePatchBootConfigData(
        RuntimeInterface $runtime,
        string $data,
        ?int $lba = null,
        ?int $bytesPerSector = null,
    ): string {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();

        if ($bootStream instanceof ISOBootImageStream
            && !$bootStream->isNoEmulation()
            && $lba !== null
            && $bytesPerSector === self::SECTOR_SIZE
        ) {
            $bootImage = $bootStream->bootImage();
            $fileIndex = $bootImage->getFileIndex();
            if ($fileIndex !== []) {
                $dataLen = strlen($data);
                $dataStart = $lba * $bytesPerSector;
                $dataEnd = $dataStart + $dataLen;
                $patched = $data;
                $applied = [];

                foreach (['CONFIG.SYS', 'AUTOEXEC.BAT'] as $filename) {
                    $info = $bootImage->getFileInfo($filename);
                    if ($info === null) {
                        continue;
                    }

                    $fileStart = $info['offset'];
                    $fileEnd = $fileStart + $info['size'];
                    if ($fileEnd <= $dataStart || $fileStart >= $dataEnd) {
                        continue;
                    }

                    $sliceStart = max(0, $fileStart - $dataStart);
                    $sliceEnd = min($dataLen, $fileEnd - $dataStart);
                    if ($sliceEnd <= $sliceStart) {
                        continue;
                    }

                    $slice = substr($patched, $sliceStart, $sliceEnd - $sliceStart);
                    $result = $this->bootConfigPatcher()->patch($slice);
                    if (!$result->isPatched()) {
                        continue;
                    }

                    $patched = substr_replace($patched, $result->data, $sliceStart, $sliceEnd - $sliceStart);
                    foreach ($result->appliedRules as $rule) {
                        $applied[$rule] = true;
                    }
                }

                if ($applied !== [] && !$this->bootConfigPatchAnnounced) {
                    $this->bootConfigPatchAnnounced = true;
                    $runtime->option()->logger()->warning(sprintf(
                        'PATCH: boot config updated (%s)',
                        implode(', ', array_keys($applied)),
                    ));
                }

                return $patched;
            }
        }

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

    private function reset(RuntimeInterface $runtime): void
    {
        // BIOS reset simply clears errors/carry.
        $this->succeed($runtime);
    }

    private function readStatus(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $addr = $dl < self::EXTSTART_HD
            ? self::BDA_FLOPPY_LAST_STATUS
            : self::BDA_DISK_LAST_STATUS;
        $status = $this->readMemory8($runtime, $addr) & 0xFF;

        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, $status);
        $runtime->memoryAccessor()->setCarryFlag($status !== 0);
    }

    private function verifySectorsCHS(RuntimeInterface $runtime, int $sectorsToVerify): void
    {
        if ($sectorsToVerify === 0 && $this->isCdromDrive($runtime, $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit())) {
            $this->succeed($runtime);
            return;
        }
        if (!$this->validateChsRequest($runtime, $sectorsToVerify)) {
            return;
        }

        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToVerify);
        $this->succeed($runtime);
    }

    private function formatTrack(RuntimeInterface $runtime, int $sectorsToFormat): void
    {
        if ($sectorsToFormat === 0 && $this->isCdromDrive($runtime, $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit())) {
            $this->succeed($runtime);
            return;
        }
        if (!$this->validateChsRequest($runtime, $sectorsToFormat)) {
            return;
        }

        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToFormat);
        $this->succeed($runtime);
    }

    private function initDriveParameters(RuntimeInterface $runtime): void
    {
        $this->succeed($runtime);
    }

    private function seek(RuntimeInterface $runtime): void
    {
        $this->succeed($runtime);
    }

    private function alternateReset(RuntimeInterface $runtime): void
    {
        $this->succeed($runtime);
    }

    private function checkDriveReady(RuntimeInterface $runtime): void
    {
        $this->succeed($runtime);
    }

    private function recalibrate(RuntimeInterface $runtime): void
    {
        $this->succeed($runtime);
    }

    private function controllerDiagnostic(RuntimeInterface $runtime): void
    {
        $this->succeed($runtime);
    }

    private function detectChange(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }

        if ($this->isFloppyDrive($runtime, $dl)) {
            // Report "no change" to avoid DOS prompting for disk reinsertion.
            $this->succeed($runtime);
            return;
        }

        $this->fail($runtime, 0x01);
    }

    private function setMediaTypeForFormat(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }

        // CD-ROM and read-only media: just acknowledge.
        $this->succeed($runtime);
    }

    private function getDriveParameters(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }

        [$cylinders, $heads, $sectors] = $this->getDriveGeometry($runtime, $dl);

        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectors); // AL = sectors per track

        $cl = ($sectors & 0x3F) | ((($cylinders >> 8) & 0x03) << 6);
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::ECX, $cl);           // CL
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::ECX, $cylinders - 1);    // CH (max cylinder number)

        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EDX, $heads - 1);    // DH (max head number)
        $driveCount = $this->isFloppyDrive($runtime, $dl)
            ? $this->floppyDriveCount($runtime)
            : ($this->isCdromDrive($runtime, $dl) ? 1 : $this->hardDriveCount($runtime));
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EDX, $driveCount & 0xFF);  // DL = number of drives

        // Return diskette parameter table pointer for floppies (INT 13h AH=08).
        if ($this->isFloppyDrive($runtime, $dl)) {
            $floppyType = 0x04;
            $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
            if ($bootStream instanceof ISOBootImageStream && !$bootStream->isNoEmulation()) {
                $mediaType = $bootStream->bootImage()->mediaType();
                if (in_array($mediaType, [
                    ElTorito::MEDIA_FLOPPY_1_2M,
                    ElTorito::MEDIA_FLOPPY_1_44M,
                    ElTorito::MEDIA_FLOPPY_2_88M,
                ], true)) {
                    $floppyType = $mediaType * 2;
                }
            }

            $runtime->memoryAccessor()->write16Bit(RegisterType::EBX, $floppyType);
            $runtime->memoryAccessor()->write16Bit(RegisterType::ES, 0xF000);
            $runtime->memoryAccessor()->write16Bit(RegisterType::EDI, 0xFE00);
        }

        $this->setDiskStatus($runtime, 0x00);
    }

    private function validateChsRequest(RuntimeInterface $runtime, int $sectors): bool
    {
        $dx = $runtime->memoryAccessor()->fetch(RegisterType::EDX);
        $dh = $dx->asHighBit();
        $dl = $dx->asLowBit();
        $mirrorB = $this->shouldMirrorFloppyDriveB($runtime, $dl);

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return false;
        }

        if ($sectors === 0 || $sectors > 128) {
            $this->fail($runtime, 0x01);
            return false;
        }

        $cx = $runtime->memoryAccessor()->fetch(RegisterType::ECX);
        $ch = $cx->asHighBit();
        $cl = $cx->asLowBit();

        $cylinder = (($cl >> 6) & 0x03) << 8;
        $cylinder |= $ch;
        $sector = $cl & 0x3F;
        $head = $dh;

        if ($sector === 0) {
            if ($mirrorB) {
                return true;
            }
            $this->fail($runtime, 0x01);
            return false;
        }

        [$cylinders, $headsPerCylinder, $sectorsPerTrack] = $this->getDriveGeometry($runtime, $dl);

        if ($head >= $headsPerCylinder || $sector > $sectorsPerTrack || $cylinder >= $cylinders) {
            if ($mirrorB) {
                return true;
            }
            $this->fail($runtime, 0x01);
            return false;
        }

        return true;
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
        $mirrorB = $this->shouldMirrorFloppyDriveB($runtime, $dl);

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01); // invalid command/drive
            return;
        }

        if ($sectorsToRead === 0) {
            $this->fail($runtime, 0x01);
            return;
        }
        if ($sectorsToRead > 128) {
            $this->fail($runtime, 0x01);
            return;
        }

        // Read from bootStream (disk image) directly, not from unified memory
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if ($bootStream === null) {
            $this->fail($runtime, 0x20);
            return;
        }
        if ($bootStream instanceof ISOBootImageStream) {
            $this->maybePatchBootImage($runtime, $bootStream);
        }

        $cylinder = (($cl >> 6) & 0x03) << 8;
        $cylinder |= $ch;
        $sector = $cl & 0x3F;
        $head = $dh;

        if ($sector === 0) {
            if ($mirrorB) {
                $sector = 1;
            } else {
            $this->fail($runtime, 0x01);
            return;
            }
        }

        [$cylinders, $headsPerCylinder, $sectorsPerTrack] = $this->getDriveGeometry($runtime, $dl);

        if ($head >= $headsPerCylinder || $sector > $sectorsPerTrack || $cylinder >= $cylinders) {
            if (!$mirrorB) {
            $this->fail($runtime, 0x01);
            return;
            }
        }

        $isCdrom = $this->isCdromDrive($runtime, $dl);
        $bytesPerSector = $isCdrom ? self::CD_SECTOR_SIZE : self::SECTOR_SIZE;
        $lba = ($cylinder * $headsPerCylinder + $head) * $sectorsPerTrack + ($sector - 1);
        $bytes = $sectorsToRead * $bytesPerSector;
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

        $this->maybeTraceInt13Read($runtime, 'READ-CHS', $lba, $sectorsToRead, $bufferAddress, $isCdrom);

        if ($isCdrom) {
            if (!$bootStream instanceof ISOBootImageStream) {
                $this->fail($runtime, 0x01);
                return;
            }

            $data = $bootStream->readIsoSectors($lba, $sectorsToRead);
            if ($data === null) {
                $this->fail($runtime, 0x20);
                return;
            }

            $data = $this->maybePatchBootConfigData($runtime, $data, $lba, self::CD_SECTOR_SIZE);
            $this->writeBlockToMemory($runtime, $bufferAddress, $data);

            $runtime->architectureProvider()
                ->instructionExecutor()
                ->invalidateCachesIfExecutedPageOverlaps($bufferAddress, strlen($data));

            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToRead);
            $this->setDiskStatus($runtime, 0x00);

            $runtime->addressMap()->register(
                max(0, $bufferAddress - 1),
                new HardDisk($dl, $lba * self::CD_SECTOR_SIZE, $lba * self::CD_SECTOR_SIZE),
            );

            $this->maybeStopOnInt13Read($runtime, 'READ-CHS', $lba, $sectorsToRead, $bufferAddress, true, $data);
            return;
        }

        // Save bootStream offset and read from disk image
        $savedBootOffset = $bootStream->offset();

        try {
            $bootStream->setOffset($lba * self::SECTOR_SIZE);
        } catch (StreamReaderException) {
            if ($mirrorB) {
                $data = str_repeat("\x00", $bytes);
                $this->writeBlockToMemory($runtime, $bufferAddress, $data);
                $bootStream->setOffset($savedBootOffset);
                $runtime->architectureProvider()
                    ->instructionExecutor()
                    ->invalidateCachesIfExecutedPageOverlaps($bufferAddress, $bytes);
                $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToRead);
                $this->setDiskStatus($runtime, 0x00);
                $runtime->addressMap()->register(
                    max(0, $bufferAddress - 1),
                    new HardDisk($dl, $lba * self::SECTOR_SIZE, $lba * self::SECTOR_SIZE),
                );
                $this->maybeStopOnInt13Read($runtime, 'READ-CHS', $lba, $sectorsToRead, $bufferAddress, false, $data);
                return;
            }
            $bootStream->setOffset($savedBootOffset);
            $this->fail($runtime, 0x20); // controller failure
            return;
        }

        $debugBytes = [];
        try {
            $data = $bootStream->read($bytes);
        } catch (StreamReaderException) {
            $bootStream->setOffset($savedBootOffset);
            if ($mirrorB) {
                $data = str_repeat("\x00", $bytes);
            } else {
                $this->fail($runtime, 0x20);
                return;
            }
        }

        if (strlen($data) !== $bytes) {
            if ($mirrorB) {
                $data = str_pad($data, $bytes, "\x00");
            } else {
                $bootStream->setOffset($savedBootOffset);
                $this->fail($runtime, 0x20);
                return;
            }
        }

        if ($bootStream instanceof ISOBootImageStream) {
            $data = $this->maybePatchBootConfigData($runtime, $data, $lba, self::SECTOR_SIZE);
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
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToRead);
        $this->setDiskStatus($runtime, 0x00);

        // Track mapping for later addressMap lookups (best-effort)
        $runtime->addressMap()->register(
            max(0, $bufferAddress - 1),
            new HardDisk($dl, $lba * self::SECTOR_SIZE, $lba * self::SECTOR_SIZE),
        );

        $this->maybeStopOnInt13Read($runtime, 'READ-CHS', $lba, $sectorsToRead, $bufferAddress, false, $data);
    }

    private function extensionsPresent(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $dl = $ma->fetch(RegisterType::EDX)->asLowBit();

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }
        if ($dl < self::EXTSTART_HD) {
            $this->fail($runtime, 0x01);
            return;
        }

        // EDD version 3.0, features: bit0 (extended disk access), bit1 (EDD)
        $ma->writeToHighBit(RegisterType::EAX, 0x30); // AH = version
        $ma->write16Bit(RegisterType::EBX, 0xAA55);
        $ma->write16Bit(RegisterType::ECX, 0x0007);
        $ma->setCarryFlag(false);
        $this->updateBdaStatus($runtime, 0x00);
    }

    private function readSectorsLBA(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }
        if ($dl < self::EXTSTART_HD) {
            $this->fail($runtime, 0x01);
            return;
        }

        // DAP is specified via DS:SI (int13ext_s). Use DS:SI as a far pointer.
        $dapLinear = $this->segmentRegisterLinearAddress($runtime, RegisterType::DS, $si, $addressSize);

        $size = $this->readMemory8($runtime, $dapLinear);
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
        if ($bootStream instanceof ISOBootImageStream) {
            $this->maybePatchBootImage($runtime, $bootStream);
        }
        $isCdrom = $this->isCdromDrive($runtime, $dl);

        $sectorCount = $this->readMemory16($runtime, $dapLinear + 2);

        // DAP buffer pointer is a real-mode segment:offset pair at +4/+6.
        $bufferOffset = $this->readMemory16($runtime, $dapLinear + 4);
        $bufferSegment = $this->readMemory16($runtime, $dapLinear + 6);
        $bufferAddress = $this->realModeSegmentOffsetLinearAddress($runtime, $bufferSegment, $bufferOffset);

        $lbaLow = $this->readMemory32($runtime, $dapLinear + 8);
        $lbaHigh = $this->readMemory32($runtime, $dapLinear + 12);
        $lba = ($lbaHigh << 32) | $lbaLow;

        // Nothing to do for a zero-length request.
        if ($sectorCount === 0) {
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, 0x00);
            $this->succeed($runtime);
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
            $isCdrom ? 'yes' : 'no'
        ));

        $runtime->logicBoard()->debug()->watchState()->notifyInt13Read($lba, $sectorCount);

        $this->maybeTraceInt13Read($runtime, 'READ-LBA', $lba, $sectorCount, $bufferAddress, $isCdrom);

        if ($isCdrom) {
            // CD-ROM drive reads use 2048-byte logical sectors.
            $data = $bootStream->readIsoSectors($lba, $sectorCount);
            if ($data === null) {
                $runtime->option()->logger()->error('INT 13h READ LBA failed: ISO read error');
                $this->fail($runtime, 0x20);
                return;
            }

            if ($bootStream instanceof ISOBootImageStream) {
                $data = $this->maybePatchBootConfigData($runtime, $data, $lba, self::CD_SECTOR_SIZE);
            }

            // Write data to memory - ISOLINUX manages its own memory layout
            // and uses INT 13h to load additional sectors to specific addresses
            $this->writeBlockToMemory($runtime, $bufferAddress, $data);

            // Invalidate caches only when we overwrote a page that has already been executed.
            $runtime->architectureProvider()
                ->instructionExecutor()
                ->invalidateCachesIfExecutedPageOverlaps($bufferAddress, strlen($data));

            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
            $this->setDiskStatus($runtime, 0x00);

            // Track mapping for later addressMap lookups
            $runtime->addressMap()->register(
                max(0, $bufferAddress - 1),
                new HardDisk($dl, $lba * self::CD_SECTOR_SIZE, $lba * self::CD_SECTOR_SIZE),
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

        if ($bootStream instanceof ISOBootImageStream) {
            $data = $this->maybePatchBootConfigData($runtime, $data, $lba, self::SECTOR_SIZE);
        }

        $this->writeBlockToMemory($runtime, $bufferAddress, $data);

        $bootStream->setOffset($savedBootOffset);

        // Invalidate caches only when we overwrote a page that has already been executed.
        $runtime->architectureProvider()
            ->instructionExecutor()
            ->invalidateCachesIfExecutedPageOverlaps($bufferAddress, strlen($data));

        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
        $this->setDiskStatus($runtime, 0x00);

        // Track mapping for later addressMap lookups (best-effort)
        $runtime->addressMap()->register(
            max(0, $bufferAddress - 1),
            new HardDisk($dl, $lba * self::SECTOR_SIZE, $lba * self::SECTOR_SIZE),
        );

        $this->maybeStopOnInt13Read($runtime, 'READ-LBA', $lba, $sectorCount, $bufferAddress, false, $data);
    }

    private function writeBlockToMemory(RuntimeInterface $runtime, int $address, string $data): void
    {
        if ($data === '') {
            return;
        }

        $len = strlen($data);
        $videoMin = VideoMemoryService::VIDEO_MEMORY_ADDRESS_STARTED;
        $videoMax = VideoMemoryService::VIDEO_MEMORY_ADDRESS_ENDED;
        $overlapsVideo = $address <= $videoMax && ($address + $len - 1) >= $videoMin;

        $memory = $runtime->memory();
        if (!$overlapsVideo && method_exists($memory, 'copyFromString')) {
            $memory->copyFromString($data, $address);
            return;
        }

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
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }
        if ($dl < self::EXTSTART_HD) {
            $this->fail($runtime, 0x01);
            return;
        }

        // Decide logical sector size based on media type.
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        $isCdRom = $this->isCdromDrive($runtime, $dl);
        $isFloppy = $this->isFloppyDrive($runtime, $dl);

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

        if ($isCdRom) {
            $cylinders = 0xFFFFFFFF;
            $heads = 0xFFFFFFFF;
            $sectors = 0xFFFFFFFF;
        } elseif ($isFloppy) {
            [$cylinders, $heads, $sectors] = $this->getDriveGeometry($runtime, $dl);
        } else {
            [$cylinders, $heads, $sectors] = $this->getDriveGeometry($runtime, $dl);
        }

        // Estimate total sector count. For CD-ROM, use the ISO size; otherwise keep a sane default.
        if ($isCdRom) {
            $isoSize = $bootStream instanceof ISOBootImageStream ? $bootStream->iso()->fileSize() : 0;
            $totalSectors = (int) max(1, intdiv($isoSize, $bytesPerSector));
        } elseif ($bootStream instanceof ISOBootImageStream) {
            $bootBytes = $bootStream->bootImage()->size();
            $totalSectors = (int) max(1, (int) ceil($bootBytes / self::SECTOR_SIZE));
        } else {
            $totalSectors = 0x0010_0000; // ~512MB worth
        }

        $ma->write16Bit($buffer, $requestedSize);
        $ma->write16Bit($buffer + 2, $isCdRom ? 0x0074 : 0x0001); // flags: CHS info valid / removable
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

        $this->setDiskStatus($runtime, 0x00);
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
        if (!$this->validateChsRequest($runtime, $sectorsToWrite)) {
            return;
        }

        // Write is a no-op for read-only media, but we accept the data
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToWrite);
        $this->setDiskStatus($runtime, 0x00);
    }

    private function writeSectorsLBA(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $dapLinear = $this->segmentRegisterLinearAddress($runtime, RegisterType::DS, $si, $addressSize);

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }
        if ($dl < self::EXTSTART_HD) {
            $this->fail($runtime, 0x01);
            return;
        }

        $size = $this->readMemory8($runtime, $dapLinear);
        if ($size < 16) {
            $this->fail($runtime, 0x01);
            return;
        }

        $sectorCount = $this->readMemory16($runtime, $dapLinear + 2);

        if ($sectorCount === 0) {
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, 0x00);
            $this->succeed($runtime);
            return;
        }

        if ($this->isCdromDrive($runtime, $dl)) {
            $this->fail($runtime, 0x03);
            return;
        }

        // Write is a no-op for read-only media, but we accept the data
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
        $this->setDiskStatus($runtime, 0x00);
    }

    private function verifySectorsLBA(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $dapLinear = $this->segmentRegisterLinearAddress($runtime, RegisterType::DS, $si, $addressSize);

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }
        if ($dl < self::EXTSTART_HD) {
            $this->fail($runtime, 0x01);
            return;
        }

        $size = $this->readMemory8($runtime, $dapLinear);
        if ($size < 16) {
            $this->fail($runtime, 0x01);
            return;
        }

        $sectorCount = $this->readMemory16($runtime, $dapLinear + 2);
        if ($sectorCount === 0) {
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, 0x00);
            $this->succeed($runtime);
            return;
        }

        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
        $this->succeed($runtime);
    }

    private function lockUnlock(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $dl = $ma->fetch(RegisterType::EDX)->asLowBit();
        $al = $ma->fetch(RegisterType::EAX)->asLowBit();

        if ($dl < self::EXTSTART_CD) {
            $this->succeed($runtime);
            return;
        }

        if (!$this->isCdromDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }

        $cdid = $dl - self::EXTSTART_CD;
        $locks = $this->cdromLocks[$cdid] ?? 0;

        switch ($al) {
            case 0x00: // lock
                if ($locks === 0xFF) {
                    $this->fail($runtime, 0xB4);
                    return;
                }
                $locks++;
                $this->cdromLocks[$cdid] = $locks;
                $ma->writeToLowBit(RegisterType::EAX, 0x01);
                $this->succeed($runtime);
                return;
            case 0x01: // unlock
                if ($locks === 0x00) {
                    $this->fail($runtime, 0xB0);
                    return;
                }
                $locks--;
                $this->cdromLocks[$cdid] = $locks;
                $ma->writeToLowBit(RegisterType::EAX, $locks > 0 ? 0x01 : 0x00);
                $this->succeed($runtime);
                return;
            case 0x02: // status
                $ma->writeToLowBit(RegisterType::EAX, $locks > 0 ? 0x01 : 0x00);
                $this->succeed($runtime);
                return;
            default:
                $this->fail($runtime, 0x01);
                return;
        }
    }

    private function eject(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        if ($dl < self::EXTSTART_CD) {
            $this->fail($runtime, 0xB2);
            return;
        }

        if (!$this->isCdromDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }

        $cdid = $dl - self::EXTSTART_CD;
        $locks = $this->cdromLocks[$cdid] ?? 0;
        if ($locks !== 0) {
            $this->fail($runtime, 0xB1);
            return;
        }

        $this->succeed($runtime);
    }

    private function seekExtended(RuntimeInterface $runtime): void
    {
        $this->succeed($runtime);
    }

    private function extendedChange(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        if ($this->isCdromDrive($runtime, $dl)) {
            $this->fail($runtime, 0x06);
            return;
        }

        $this->succeed($runtime);
    }

    private function setHardwareConfig(RuntimeInterface $runtime): void
    {
        $this->succeed($runtime);
    }

    private function getDiskType(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $ma = $runtime->memoryAccessor();

        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }

        if ($this->isCdromDrive($runtime, $dl)) {
            // CD-ROM (SeaBIOS reports as type 1)
            $ma->writeToHighBit(RegisterType::EAX, 0x01);
            $ma->setCarryFlag(false);
            $this->updateBdaStatus($runtime, 0x00);
            return;
        }

        if ($this->isFloppyDrive($runtime, $dl)) {
            // Floppy drive (SeaBIOS reports as type 1).
            $ma->writeToHighBit(RegisterType::EAX, 0x01);
            $ma->setCarryFlag(false);
            $this->updateBdaStatus($runtime, 0x00);
            return;
        }

        // Hard disk - return type 3
        $ma->writeToHighBit(RegisterType::EAX, 0x03);
        // CX:DX = number of 512-byte sectors
        $totalSectors = 0x0010_0000; // ~512MB
        $ma->write16Bit(RegisterType::ECX, ($totalSectors >> 16) & 0xFFFF);
        $ma->write16Bit(RegisterType::EDX, $totalSectors & 0xFFFF);

        $ma->setCarryFlag(false);
        $this->updateBdaStatus($runtime, 0x00);
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

    private function getDriveGeometry(RuntimeInterface $runtime, int $dl): array
    {
        if ($this->isFloppyDrive($runtime, $dl)) {
            return $this->getFloppyGeometry($runtime);
        }
        if ($this->isCdromDrive($runtime, $dl)) {
            return $this->getCdromGeometry($runtime);
        }

        return $this->getHardDiskGeometry($runtime);
    }

    /**
     * @return array{int,int,int} [cylinders, heads, sectors]
     */
    private function getCdromGeometry(RuntimeInterface $runtime): array
    {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        $totalSectors = 0x10000;
        if ($bootStream instanceof ISOBootImageStream) {
            $size = $bootStream->iso()->fileSize();
            if ($size > 0) {
                $totalSectors = (int) max(1, intdiv($size + self::CD_SECTOR_SIZE - 1, self::CD_SECTOR_SIZE));
            }
        }

        $heads = 16;
        $sectorsPerTrack = 63;
        $cylinders = (int) max(1, intdiv($totalSectors + ($heads * $sectorsPerTrack - 1), $heads * $sectorsPerTrack));
        if ($cylinders > 1024) {
            $cylinders = 1024;
        }

        return [$cylinders, $heads, $sectorsPerTrack];
    }

    /**
     * @return array{int,int,int} [cylinders, heads, sectors]
     */
    private function getFloppyGeometry(RuntimeInterface $runtime): array
    {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if ($bootStream instanceof ISOBootImageStream && !$bootStream->isNoEmulation()) {
            $mediaType = $bootStream->bootImage()->mediaType();
            return match ($mediaType) {
                ElTorito::MEDIA_FLOPPY_1_2M => [80, 2, 15],
                ElTorito::MEDIA_FLOPPY_1_44M => [80, 2, 18],
                ElTorito::MEDIA_FLOPPY_2_88M => [80, 2, 36],
                default => [self::FLOPPY_CYLINDERS, self::FLOPPY_HEADS, self::FLOPPY_SECTORS_PER_TRACK],
            };
        }

        return [self::FLOPPY_CYLINDERS, self::FLOPPY_HEADS, self::FLOPPY_SECTORS_PER_TRACK];
    }

    /**
     * @return array{int,int,int} [cylinders, heads, sectors]
     */
    private function getHardDiskGeometry(RuntimeInterface $runtime): array
    {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if ($bootStream instanceof ISOBootImageStream && !$bootStream->isNoEmulation()) {
            $mediaType = $bootStream->bootImage()->mediaType();
            if ($mediaType === ElTorito::MEDIA_HARD_DISK) {
                $mbr = $bootStream->bootImage()->readAt(0, 512);
                $geom = $this->parseMbrGeometry($mbr);
                if ($geom !== null) {
                    return $geom;
                }
            }
        }

        return [1024, self::HEADS_PER_CYLINDER, self::SECTORS_PER_TRACK];
    }

    /**
     * @return array{int,int,int}|null [cylinders, heads, sectors]
     */
    private function parseMbrGeometry(string $sector): ?array
    {
        if (strlen($sector) < 512) {
            return null;
        }

        $sig = (ord($sector[510]) | (ord($sector[511]) << 8)) & 0xFFFF;
        if ($sig !== 0xAA55) {
            return null;
        }

        $entryOffset = 0x1BE;
        $lastHead = ord($sector[$entryOffset + 5] ?? "\x00");
        $sptcyl = ord($sector[$entryOffset + 6] ?? "\x00");
        $cyllow = ord($sector[$entryOffset + 7] ?? "\x00");

        $sectors = $sptcyl & 0x3F;
        $cylinder = $cyllow | (($sptcyl & 0xC0) << 2);
        $heads = $lastHead + 1;
        $cylinders = $cylinder + 1;

        if ($sectors === 0 || $heads === 0 || $cylinders === 0) {
            return null;
        }

        return [$cylinders, $heads, $sectors];
    }

    private function isCdromDrive(RuntimeInterface $runtime, int $dl): bool
    {
        if ($dl < self::EXTSTART_CD || $dl > 0xFF) {
            return false;
        }

        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        return $bootStream instanceof ISOBootImageStream;
    }

    private function isValidBiosDrive(RuntimeInterface $runtime, int $dl): bool
    {
        if ($this->isCdromDrive($runtime, $dl)) {
            return true;
        }

        if ($dl < self::EXTSTART_HD) {
            $count = $this->floppyDriveCount($runtime);
            if ($dl < $count) {
                return true;
            }
            if ($dl === 1 && $this->isElToritoFloppyEmulation($runtime)) {
                return true;
            }
            return false;
        }

        if ($dl >= self::EXTSTART_CD) {
            return false;
        }

        $count = $this->hardDriveCount($runtime);
        return ($dl - self::EXTSTART_HD) < $count;
    }

    private function isFloppyDrive(RuntimeInterface $runtime, int $dl): bool
    {
        return $dl < self::EXTSTART_HD;
    }

    private function isElToritoFloppyEmulation(RuntimeInterface $runtime): bool
    {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if (!$bootStream instanceof ISOBootImageStream || $bootStream->isNoEmulation()) {
            return false;
        }

        $mediaType = $bootStream->bootImage()->mediaType();
        return in_array($mediaType, [
            ElTorito::MEDIA_FLOPPY_1_2M,
            ElTorito::MEDIA_FLOPPY_1_44M,
            ElTorito::MEDIA_FLOPPY_2_88M,
        ], true);
    }

    private function shouldMirrorFloppyDriveB(RuntimeInterface $runtime, int $dl): bool
    {
        return $dl === 1 && $this->isElToritoFloppyEmulation($runtime);
    }

    /**
     * @return array{size:int,media:int,emulated_drive:int,controller_index:int,ilba:int,device_spec:int,buffer_segment:int,load_segment:int,sector_count:int,chs:array{heads:int,sptcyl:int,cyllow:int}}
     */
    private function buildCdEmuState(RuntimeInterface $runtime, ISOBootImageStream $bootStream): array
    {
        $bootImage = $bootStream->bootImage();
        $mediaType = $bootImage->mediaType();
        $isNoEmulation = $bootStream->isNoEmulation();

        $emulatedDrive = $isNoEmulation
            ? self::EXTSTART_CD
            : ($mediaType === ElTorito::MEDIA_HARD_DISK ? self::EXTSTART_HD : 0x00);

        $loadSegment = $bootImage->loadSegment();
        if ($loadSegment === 0) {
            $loadSegment = 0x07C0;
        }

        $chs = ['heads' => 0x00, 'sptcyl' => 0x00, 'cyllow' => 0x00];
        if (!$isNoEmulation) {
            if ($mediaType === ElTorito::MEDIA_HARD_DISK) {
                [$cylinders, $heads, $sectors] = $this->getHardDiskGeometry($runtime);
            } else {
                [$cylinders, $heads, $sectors] = $this->getFloppyGeometry($runtime);
            }

            $lastCyl = max(0, $cylinders - 1);
            $lastHead = max(0, $heads - 1);
            $sptcyl = ($sectors & 0x3F) | (((($lastCyl >> 8) & 0x03) << 6));

            $chs = [
                'heads' => $lastHead & 0xFF,
                'sptcyl' => $sptcyl & 0xFF,
                'cyllow' => $lastCyl & 0xFF,
            ];
        }

        return [
            'size' => 0x13,
            'media' => $isNoEmulation ? 0x00 : $mediaType,
            'emulated_drive' => $emulatedDrive,
            'controller_index' => 0x00,
            'ilba' => $bootImage->loadRBA(),
            'device_spec' => 0x0000,
            'buffer_segment' => 0x0000,
            'load_segment' => $loadSegment & 0xFFFF,
            'sector_count' => $bootImage->catalogSectorCount() & 0xFFFF,
            'chs' => $chs,
        ];
    }

    /**
     * Handle El Torito boot info/termination (INT 13h AH=4Bh).
     *
     * We implement AL=01h (Get Boot Info) which isolinux relies on, and fall back
     * to success for termination requests.
     */
    private function handleBootInfo(RuntimeInterface $runtime, int $al): void
    {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if (!$bootStream instanceof ISOBootImageStream) {
            $this->fail($runtime, 0x01);
            return;
        }

        $addressSize = $runtime->context()->cpu()->addressSize();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);
        $buffer = $this->segmentRegisterLinearAddress($runtime, RegisterType::DS, $si, $addressSize);

        $state = $this->buildCdEmuState($runtime, $bootStream);
        $ma = $runtime->memoryAccessor();
        $ma->allocate($buffer, 0x13, safe: false);

        $writeWord = static function (int $addr, int $value) use ($ma): void {
            $ma->writeRawByte($addr, $value & 0xFF);
            $ma->writeRawByte($addr + 1, ($value >> 8) & 0xFF);
        };
        $writeDword = static function (int $addr, int $value) use ($writeWord): void {
            $writeWord($addr, $value & 0xFFFF);
            $writeWord($addr + 2, ($value >> 16) & 0xFFFF);
        };

        $ma->writeRawByte($buffer + 0, $state['size']);
        $ma->writeRawByte($buffer + 1, $state['media']);
        $ma->writeRawByte($buffer + 2, $state['emulated_drive']);
        $ma->writeRawByte($buffer + 3, $state['controller_index']);
        $writeDword($buffer + 4, $state['ilba']);
        $writeWord($buffer + 8, $state['device_spec']);
        $writeWord($buffer + 10, $state['buffer_segment']);
        $writeWord($buffer + 12, $state['load_segment']);
        $writeWord($buffer + 14, $state['sector_count']);
        $ma->writeRawByte($buffer + 16, $state['chs']['heads']);
        $ma->writeRawByte($buffer + 17, $state['chs']['sptcyl']);
        $ma->writeRawByte($buffer + 18, $state['chs']['cyllow']);

        $runtime->option()->logger()->debug(sprintf(
            'INT 13h GET BOOT INFO: DL=0x%02X media=0x%02X LBA=%d loadSeg=0x%04X sectors=%d buffer=0x%05X',
            $state['emulated_drive'],
            $state['media'],
            $state['ilba'],
            $state['load_segment'],
            $state['sector_count'],
            $buffer
        ));

        $this->setDiskStatus($runtime, 0x00);
    }

    private function unsupported(RuntimeInterface $runtime, int $command): void
    {
        $runtime->option()->logger()->error(sprintf('Disk interrupt command 0x%02X not supported yet', $command));
        $this->fail($runtime, 0x01);
    }

    private function succeed(RuntimeInterface $runtime): void
    {
        $this->setDiskStatus($runtime, 0x00);
    }

    private function fail(RuntimeInterface $runtime, int $status): void
    {
        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        $ah = $ax->asHighBit();
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $logger = $runtime->option()->logger();
        $message = sprintf(
            'INT 13h failed with status 0x%02X (AH=0x%02X DL=0x%02X)',
            $status,
            $ah,
            $dl
        );
        if ($status === 0x01) {
            $logger->debug($message);
        } else {
            $logger->error($message);
        }
        $this->setDiskStatus($runtime, $status);
    }

    private function setDiskStatus(RuntimeInterface $runtime, int $status): void
    {
        $ma = $runtime->memoryAccessor();
        $dl = $ma->fetch(RegisterType::EDX)->asLowBit();
        $ma->writeToHighBit(RegisterType::EAX, $status & 0xFF);
        $ma->setCarryFlag($status !== 0);
        $this->updateBdaStatus($runtime, $status, $dl);
    }

    private function updateBdaStatus(RuntimeInterface $runtime, int $status, ?int $dl = null): void
    {
        $ma = $runtime->memoryAccessor();
        $dl ??= $ma->fetch(RegisterType::EDX)->asLowBit();
        $addr = $dl < self::EXTSTART_HD
            ? self::BDA_FLOPPY_LAST_STATUS
            : self::BDA_DISK_LAST_STATUS;
        $ma->writeBySize($addr, $status & 0xFF, 8);
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

    private function readUint16LE(string $data, int $offset): int
    {
        $lo = ord($data[$offset] ?? "\x00");
        $hi = ord($data[$offset + 1] ?? "\x00");
        return ($lo | ($hi << 8)) & 0xFFFF;
    }

    private function buildDosDriverStub(string $deviceName): string
    {
        $name = str_pad(substr($deviceName, 0, 8), 8, ' ');
        $header = pack('V', 0xFFFFFFFF);
        $header .= pack('v', 0x8000);
        $header .= pack('v', 0x0020);
        $header .= pack('v', 0x0030);
        $header .= $name;
        $header .= str_repeat("\x00", 32 - strlen($header));

        $reqOff = 0x46;
        $reqSeg = 0x48;

        $strategy = "\x1E"
            . "\x8C\xC8"
            . "\x8E\xD8"
            . "\x89\x1E" . pack('v', $reqOff)
            . "\x8C\x06" . pack('v', $reqSeg)
            . "\x1F"
            . "\xCB";

        $interrupt = "\x1E"
            . "\x8C\xC8"
            . "\x8E\xD8"
            . "\x8B\x1E" . pack('v', $reqOff)
            . "\x8E\x06" . pack('v', $reqSeg)
            . "\x26\xC7\x47\x03\x00\x81"
            . "\x1F"
            . "\xCB";

        $pad1 = str_repeat("\x00", max(0, 0x30 - (0x20 + strlen($strategy))));
        $pad2 = str_repeat("\x00", max(0, 0x46 - (0x30 + strlen($interrupt))));

        return $header . $strategy . $pad1 . $interrupt . $pad2 . str_repeat("\x00", 4);
    }

}
