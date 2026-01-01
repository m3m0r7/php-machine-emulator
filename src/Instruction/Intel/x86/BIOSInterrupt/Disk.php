<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\Intel\Service\VideoMemoryService;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\Media\MediaContext;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Disk implements InterruptInterface
{
    use DiskCHS;
    use DiskCDROM;
    use DiskFloppy;

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
                $matchedOffsetBytes = $i * ($isCdrom ? MediaContext::CD_SECTOR_SIZE : MediaContext::SECTOR_SIZE);
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
        } else {
            $total = $executor->instructionCount();
            if ($total > 0) {
                $runtime->option()->logger()->warning(sprintf(
                    'INSNS: total=%d',
                    (int) $total,
                ));
            }
        }

        throw new HaltException('Stopped by PHPME_STOP_ON_INT13_READ_LBA');
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
        if (!$overlapsVideo) {
            $memory->copyFromString($data, $address);
            return;
        }

        $runtime->memoryAccessor()->allocate($address, $len, safe: false);
        for ($i = 0; $i < $len; $i++) {
            $runtime->memoryAccessor()->writeRawByte($address + $i, ord($data[$i]));
        }
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

    private function isValidBiosDrive(RuntimeInterface $runtime, int $dl): bool
    {
        return $runtime->logicBoard()->media()->driveTypeForBiosNumber($dl) !== null;
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

}
