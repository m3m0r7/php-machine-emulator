<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Disk\HardDisk;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\Media\MediaContext;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\ISO\ElTorito;

trait DiskCHS
{
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
            $bootImage = $bootStream?->bootImage();
            if ($bootStream !== null && $bootImage !== null && !$bootStream->isNoEmulation()) {
                $mediaType = $bootImage->mediaType();
                if (
                    in_array($mediaType, [
                    ElTorito::MEDIA_FLOPPY_1_2M,
                    ElTorito::MEDIA_FLOPPY_1_44M,
                    ElTorito::MEDIA_FLOPPY_2_88M,
                    ], true)
                ) {
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
        $bytesPerSector = $isCdrom ? MediaContext::CD_SECTOR_SIZE : MediaContext::SECTOR_SIZE;
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
            $cylinder,
            $head,
            $sector,
            $lba,
            $sectorsToRead,
            $es,
            $bx,
            $bufferAddress,
            $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(),
            $runtime->memory()->offset() & 0xFFFF
        ));

        $this->maybeTraceInt13Read($runtime, 'READ-CHS', $lba, $sectorsToRead, $bufferAddress, $isCdrom);

        if ($isCdrom) {
            if ($bootStream->bootImage() === null) {
                $this->fail($runtime, 0x01);
                return;
            }

            $data = $bootStream->readIsoSectors($lba, $sectorsToRead);
            if ($data === null) {
                $this->fail($runtime, 0x20);
                return;
            }

            $this->writeBlockToMemory($runtime, $bufferAddress, $data);

            $runtime->architectureProvider()
                ->instructionExecutor()
                ->invalidateCachesIfExecutedPageOverlaps($bufferAddress, strlen($data));

            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToRead);
            $this->setDiskStatus($runtime, 0x00);

            $runtime->addressMap()->register(
                max(0, $bufferAddress - 1),
                new HardDisk($dl, $lba * MediaContext::CD_SECTOR_SIZE, $lba * MediaContext::CD_SECTOR_SIZE),
            );

            $this->maybeStopOnInt13Read($runtime, 'READ-CHS', $lba, $sectorsToRead, $bufferAddress, true, $data);
            return;
        }

        // Save bootStream offset and read from disk image
        $savedBootOffset = $bootStream->offset();

        try {
            $bootStream->setOffset($lba * MediaContext::SECTOR_SIZE);
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
                    new HardDisk($dl, $lba * MediaContext::SECTOR_SIZE, $lba * MediaContext::SECTOR_SIZE),
                );
                $this->maybeStopOnInt13Read($runtime, 'READ-CHS', $lba, $sectorsToRead, $bufferAddress, false, $data);
                return;
            }
            $this->fail($runtime, 0x20);
            return;
        }

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

        $this->writeBlockToMemory($runtime, $bufferAddress, $data);

        // Debug first 16 bytes
        $debugBytes = [];
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

        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorsToRead);
        $this->setDiskStatus($runtime, 0x00);

        // Track mapping for later addressMap lookups (best-effort)
        $runtime->addressMap()->register(
            max(0, $bufferAddress - 1),
            new HardDisk($dl, $lba * MediaContext::SECTOR_SIZE, $lba * MediaContext::SECTOR_SIZE),
        );

        $this->maybeStopOnInt13Read($runtime, 'READ-CHS', $lba, $sectorsToRead, $bufferAddress, false, $data);
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

    private function hardDriveCount(RuntimeInterface $runtime): int
    {
        [, $hardDriveCount] = $runtime->logicBoard()->media()->bootDriveCounts();
        return $hardDriveCount;
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
    private function getHardDiskGeometry(RuntimeInterface $runtime): array
    {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        $bootImage = $bootStream?->bootImage();
        if ($bootStream !== null && $bootImage !== null && !$bootStream->isNoEmulation()) {
            $mediaType = $bootImage->mediaType();
            if ($mediaType === ElTorito::MEDIA_HARD_DISK) {
                $mbr = $bootImage->readAt(0, 512);
                $geom = $this->parseMbrGeometry($mbr);
                if ($geom !== null) {
                    return $geom;
                }
            }
        }

        $sizeBytes = 0;
        if ($bootStream !== null && $bootImage !== null && !$bootStream->isNoEmulation()) {
            $sizeBytes = $bootImage->size();
        } elseif ($bootStream !== null) {
            $sizeBytes = $bootStream->backingFileSize();
        }

        return $runtime->logicBoard()->media()->hardDiskGeometryFromSize($sizeBytes);
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
}
