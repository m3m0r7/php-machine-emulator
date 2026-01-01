<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Disk\HardDisk;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\Media\DriveType;
use PHPMachineEmulator\LogicBoard\Media\MediaContext;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\BootableStreamInterface;
use PHPMachineEmulator\Stream\ISO\ElTorito;

trait DiskCDROM
{
    private function extensionsPresent(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }
        if ($dl < self::EXTSTART_HD) {
            $this->fail($runtime, 0x01);
            return;
        }

        // EDD version 3.0, features: bit0 (extended disk access), bit1 (EDD)
        $ma = $runtime->memoryAccessor();
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
        $isCdrom = $this->isCdromDrive($runtime, $dl);

        $sectorCount = $this->readMemory16($runtime, $dapLinear + 2);

        $linearMask = $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        if ($size >= 0x18) {
            // EDD v3.0: 64-bit linear buffer address at +4, 64-bit LBA at +0x0C.
            $bufferLow = $this->readMemory32($runtime, $dapLinear + 4) & 0xFFFFFFFF;
            $bufferHigh = $this->readMemory32($runtime, $dapLinear + 8) & 0xFFFFFFFF;
            $bufferAddress = (($bufferHigh << 32) | $bufferLow) & $linearMask;

            $lbaLow = $this->readMemory32($runtime, $dapLinear + 0x0C) & 0xFFFFFFFF;
            $lbaHigh = $this->readMemory32($runtime, $dapLinear + 0x10) & 0xFFFFFFFF;
        } else {
            // EDD v1.x: real-mode segment:offset buffer pointer at +4/+6.
            $bufferOffset = $this->readMemory16($runtime, $dapLinear + 4);
            $bufferSegment = $this->readMemory16($runtime, $dapLinear + 6);
            $bufferAddress = $this->realModeSegmentOffsetLinearAddress($runtime, $bufferSegment, $bufferOffset);

            $lbaLow = $this->readMemory32($runtime, $dapLinear + 8) & 0xFFFFFFFF;
            $lbaHigh = $this->readMemory32($runtime, $dapLinear + 12) & 0xFFFFFFFF;
        }
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
                new HardDisk($dl, $lba * MediaContext::CD_SECTOR_SIZE, $lba * MediaContext::CD_SECTOR_SIZE),
            );

            $this->maybeStopOnInt13Read($runtime, 'READ-LBA', $lba, $sectorCount, $bufferAddress, true, $data);
            return;
        }

        // Standard disk - use 512-byte sectors
        $bytes = $sectorCount * MediaContext::SECTOR_SIZE;

        // Save and restore bootStream offset
        $savedBootOffset = $bootStream->offset();

        try {
            $bootStream->setOffset($lba * MediaContext::SECTOR_SIZE);
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

        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
        $this->setDiskStatus($runtime, 0x00);

        // Track mapping for later addressMap lookups (best-effort)
        $runtime->addressMap()->register(
            max(0, $bufferAddress - 1),
            new HardDisk($dl, $lba * MediaContext::SECTOR_SIZE, $lba * MediaContext::SECTOR_SIZE),
        );

        $this->maybeStopOnInt13Read($runtime, 'READ-LBA', $lba, $sectorCount, $bufferAddress, false, $data);
    }

    private function writeSectorsLBA(RuntimeInterface $runtime): void
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

        if ($this->isCdromDrive($runtime, $dl)) {
            $this->fail($runtime, 0x03);
            return;
        }

        if ($size >= 0x18) {
            $bufferLow = $this->readMemory32($runtime, $dapLinear + 4) & 0xFFFFFFFF;
            $bufferHigh = $this->readMemory32($runtime, $dapLinear + 8) & 0xFFFFFFFF;
            $linearMask = $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
            $bufferAddress = (($bufferHigh << 32) | $bufferLow) & $linearMask;

            $lbaLow = $this->readMemory32($runtime, $dapLinear + 0x0C) & 0xFFFFFFFF;
            $lbaHigh = $this->readMemory32($runtime, $dapLinear + 0x10) & 0xFFFFFFFF;
        } else {
            $bufferOffset = $this->readMemory16($runtime, $dapLinear + 4);
            $bufferSegment = $this->readMemory16($runtime, $dapLinear + 6);
            $bufferAddress = $this->realModeSegmentOffsetLinearAddress($runtime, $bufferSegment, $bufferOffset);

            $lbaLow = $this->readMemory32($runtime, $dapLinear + 8) & 0xFFFFFFFF;
            $lbaHigh = $this->readMemory32($runtime, $dapLinear + 12) & 0xFFFFFFFF;
        }

        $lba = ($lbaHigh << 32) | $lbaLow;
        $bytes = $sectorCount * MediaContext::SECTOR_SIZE;
        $data = $this->readBlockFromMemory($runtime, $bufferAddress, $bytes);
        $written = $this->writeToBootStream($runtime, $lba * MediaContext::SECTOR_SIZE, $data);
        if (!$written) {
            $runtime->option()->logger()->debug('INT 13h WRITE LBA: write skipped (read-only or out-of-range)');
        }

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
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
        $this->setDiskStatus($runtime, 0x00);
    }

    private function getDriveParametersExtended(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
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

        $bytesPerSector = $isCdRom ? MediaContext::CD_SECTOR_SIZE : MediaContext::SECTOR_SIZE;

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
            $isoSize = $bootStream->backingFileSize();
            $totalSectors = (int) max(1, intdiv($isoSize, $bytesPerSector));
        } elseif ($bootStream->bootImage() !== null) {
            $bootBytes = $bootStream->bootImage()->size();
            $totalSectors = (int) max(1, (int) ceil($bootBytes / MediaContext::SECTOR_SIZE));
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

    /**
     * @return array{int,int,int} [cylinders, heads, sectors]
     */
    private function getCdromGeometry(RuntimeInterface $runtime): array
    {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        $totalSectors = 0x10000;
        if ($bootStream !== null) {
            $size = $bootStream->backingFileSize();
            if ($size > 0) {
                $totalSectors = (int) max(1, intdiv($size + MediaContext::CD_SECTOR_SIZE - 1, MediaContext::CD_SECTOR_SIZE));
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

    private function isCdromDrive(RuntimeInterface $runtime, int $dl): bool
    {
        $type = $runtime->logicBoard()->media()->driveTypeForBiosNumber($dl);
        return $type === DriveType::CD_ROM || $type === DriveType::CD_RAM;
    }

    /**
     * @return array{size:int,media:int,emulated_drive:int,controller_index:int,ilba:int,device_spec:int,buffer_segment:int,load_segment:int,sector_count:int,chs:array{heads:int,sptcyl:int,cyllow:int}}
     */
    private function buildCdEmuState(RuntimeInterface $runtime, BootableStreamInterface $bootStream): array
    {
        $bootImage = $bootStream->bootImage();
        if ($bootImage === null) {
            return [
                'size' => 0x13,
                'media' => 0x00,
                'emulated_drive' => self::EXTSTART_CD,
                'controller_index' => 0x00,
                'ilba' => 0,
                'device_spec' => 0x0000,
                'buffer_segment' => 0x0000,
                'load_segment' => 0x0000,
                'sector_count' => 0x0000,
                'chs' => ['heads' => 0x00, 'sptcyl' => 0x00, 'cyllow' => 0x00],
            ];
        }
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
        if ($bootStream === null || $bootStream->bootImage() === null) {
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

        $ma->writeRawByte($buffer + 0, $state['size']);
        $ma->writeRawByte($buffer + 1, $state['media']);
        $ma->writeRawByte($buffer + 2, $state['emulated_drive']);
        $ma->writeRawByte($buffer + 3, $state['controller_index']);
        $ma->writeBySize($buffer + 4, $state['ilba'], 32);
        $ma->write16Bit($buffer + 8, $state['device_spec']);
        $ma->write16Bit($buffer + 10, $state['buffer_segment']);
        $ma->write16Bit($buffer + 12, $state['load_segment']);
        $ma->write16Bit($buffer + 14, $state['sector_count']);
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
}
