<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Disk\HardDisk;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Stream\ISO\ISO9660;
use PHPMachineEmulator\Stream\ISO\ISOBootImageStream;

class Disk implements InterruptInterface
{
    private const SECTOR_SIZE = BIOS::READ_SIZE_PER_SECTOR;
    private const CD_SECTOR_SIZE = ISO9660::SECTOR_SIZE;
    private const SECTORS_PER_TRACK = 63;
    private const HEADS_PER_CYLINDER = 16;

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

    private function reset(RuntimeInterface $runtime): void
    {
        // BIOS reset simply clears errors/carry.
        $runtime->memoryAccessor()->setCarryFlag(false);
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00);
    }

    private function getDriveParameters(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();

        // Use appropriate geometry based on drive type
        if ($dl < 0x80) {
            // 1.44MB floppy geometry
            $heads = 2;
            $sectors = 18;
            $cylinders = 80;
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
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EDX, $dl < 0x80 ? 0x01 : 0x01);  // DL = number of drives

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

        if ($sectorsToRead === 0) {
            $this->fail($runtime, 0x04); // sector not found
            return;
        }

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
            $sectorsPerTrack = 18;
            $headsPerCylinder = 2;
        } else {
            $sectorsPerTrack = self::SECTORS_PER_TRACK;
            $headsPerCylinder = self::HEADS_PER_CYLINDER;
        }

        $lba = ($cylinder * $headsPerCylinder + $head) * $sectorsPerTrack + ($sector - 1);
        $bytes = $sectorsToRead * self::SECTOR_SIZE;
        $bufferAddress = $this->segmentLinearAddress($runtime, $es, $bx, $addressSize);

        // Also debug the [0x01FA] value for MikeOS
        $bootLoadAddress = $runtime->logicBoard()->media()->primary()?->stream()?->loadAddress() ?? 0x7C00;
        $bufPtr = $this->readMemory16($runtime, $bootLoadAddress + 0x01FA);
        $runtime->option()->logger()->debug(sprintf(
            'INT 13h READ CHS: C=%d H=%d S=%d => LBA=%d, sectors=%d, ES:BX=%04X:%04X linear=0x%05X CS:IP=%04X:%04X',
            $cylinder, $head, $sector, $lba, $sectorsToRead, $es, $bx, $bufferAddress,
            $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(),
            $runtime->memory()->offset() & 0xFFFF
        ));

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
        for ($i = 0; $i < $bytes; $i++) {
            try {
                $byte = $bootStream->byte();
            } catch (StreamReaderException) {
                $bootStream->setOffset($savedBootOffset);
                $this->fail($runtime, 0x20);
                return;
            }

            $address = $bufferAddress + $i;
            $runtime->memoryAccessor()->allocate($address, safe: false);
            $runtime->memoryAccessor()->writeRawByte($address, $byte);

            // Debug first 16 bytes
            if ($i < 16) {
                $debugBytes[] = sprintf('%02X', $byte);
            }
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

        // Invalidate instruction decode/translation caches for the affected memory region
        // This is critical when loading code (e.g., program executables) to memory,
        // as the old cached instructions would be stale
        $runtime->architectureProvider()->instructionExecutor()->invalidateCaches();

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
        $dapLinear = $this->segmentLinearAddress($runtime, $ds, $si, $addressSize);

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

        // DAP structure (byte-addressable):
        // Offset 0: size (1 byte)
        // Offset 1: reserved (1 byte)
        // Offset 2-3: sector count (16-bit little-endian)
        // Offset 4-5: buffer offset (16-bit little-endian)
        // Offset 6-7: buffer segment (16-bit little-endian)
        // Offset 8-15: LBA (64-bit little-endian)

        $sectorCount = $this->readMemory16($runtime, $dapLinear + 2);
        $bufferOffset = $this->readMemory16($runtime, $dapLinear + 4);
        $bufferSegment = $this->readMemory16($runtime, $dapLinear + 6);
        $lba = $this->readMemory32($runtime, $dapLinear + 8); // Only use lower 32 bits

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

        $bufferAddress = $this->segmentLinearAddress($runtime, $bufferSegment, $bufferOffset, $addressSize);

        // Read from bootStream (disk image) directly
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        if ($bootStream === null) {
            $this->fail($runtime, 0x20);
            return;
        }

        // Check if this is a No Emulation CD-ROM boot
        $isNoEmulationCdrom = ($bootStream instanceof ISOBootImageStream) && $bootStream->isNoEmulation();

        $runtime->option()->logger()->debug(sprintf(
            'INT 13h READ LBA: DS:SI=%04X:%04X LBA=%d sectors=%d => linear=0x%05X (CD-ROM=%s)',
            $ds,
            $si,
            $lba,
            $sectorCount,
            $bufferAddress,
            $isNoEmulationCdrom ? 'yes' : 'no'
        ));

        if ($isNoEmulationCdrom) {
            // For No Emulation CD-ROM, read directly from ISO using 2048-byte sectors
            $data = $bootStream->readIsoSectors($lba, $sectorCount);
            if ($data === null) {
                $runtime->option()->logger()->error('INT 13h READ LBA failed: ISO read error');
                $this->fail($runtime, 0x20);
                return;
            }

            // Write data to memory - ISOLINUX manages its own memory layout
            // and uses INT 13h to load additional sectors to specific addresses
            $dataLen = strlen($data);
            for ($i = 0; $i < $dataLen; $i++) {
                $address = $bufferAddress + $i;
                $runtime->memoryAccessor()->allocate($address, safe: false);
                $runtime->memoryAccessor()->writeRawByte($address, ord($data[$i]));
            }

            // Invalidate instruction caches when loading code
            $runtime->architectureProvider()->instructionExecutor()->invalidateCaches();

            $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00);
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
            $runtime->memoryAccessor()->setCarryFlag(false);

            // Track mapping for later addressMap lookups
            $runtime->addressMap()->register(
                max(0, $bufferAddress - 1),
                new HardDisk(0x80, $lba * self::CD_SECTOR_SIZE, $lba * self::CD_SECTOR_SIZE),
            );
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

        for ($i = 0; $i < $bytes; $i++) {
            try {
                $byte = $bootStream->byte();
            } catch (StreamReaderException) {
                $bootStream->setOffset($savedBootOffset);
                $runtime->option()->logger()->error('INT 13h READ LBA failed: read error');
                $this->fail($runtime, 0x20);
                return;
            }

            $address = $bufferAddress + $i;
            $runtime->memoryAccessor()->allocate($address, safe: false);
            $runtime->memoryAccessor()->writeRawByte($address, $byte);
        }

        $bootStream->setOffset($savedBootOffset);

        // Invalidate instruction caches when loading code
        $runtime->architectureProvider()->instructionExecutor()->invalidateCaches();

        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x00);
        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $sectorCount);
        $runtime->memoryAccessor()->setCarryFlag(false);

        // Track mapping for later addressMap lookups (best-effort)
        $runtime->addressMap()->register(
            max(0, $bufferAddress - 1),
            new HardDisk(0x80, $lba * self::SECTOR_SIZE, $lba * self::SECTOR_SIZE),
        );
    }

    private function getDriveParametersExtended(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);
        $buffer = $this->segmentLinearAddress($runtime, $ds, $si, $addressSize);

        // Decide geometry based on media type. For El Torito no-emulation CD boot,
        // the logical sector size must be 2048 bytes instead of the default 512.
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        $isCdRom = ($bootStream instanceof ISOBootImageStream) && $bootStream->isNoEmulation();

        $bytesPerSector = $isCdRom ? self::CD_SECTOR_SIZE : self::SECTOR_SIZE;
        $cylinders = 1024;
        $heads = self::HEADS_PER_CYLINDER;
        $sectors = self::SECTORS_PER_TRACK;

        // Estimate total sector count. For CD-ROM, use the ISO size; otherwise keep the old default.
        if ($isCdRom) {
            $isoSize = $bootStream->iso()->fileSize();
            $totalSectors = (int) max(1, floor($isoSize / $bytesPerSector));
        } else {
            $totalSectors = 0x0010_0000; // ~512MB worth
        }

        $ma = $runtime->memoryAccessor();

        // size
        $ma->allocate($buffer, 0x1E, safe: false);
        $ma->write16Bit($buffer, 0x1E);
        $ma->write16Bit($buffer + 2, 0x0001); // flags: CHS valid
        $ma->write16Bit($buffer + 4, $cylinders - 1);
        $ma->write16Bit($buffer + 6, $heads - 1);
        $ma->write16Bit($buffer + 8, $sectors);
        // Total sectors 64-bit
        for ($i = 0; $i < 8; $i++) {
            $ma->writeBySize($buffer + 10 + $i, ($totalSectors >> ($i * 8)) & 0xFF, 8);
        }
        // Bytes per sector
        $ma->write16Bit($buffer + 18, $bytesPerSector);
        // EDD configuration params (set to zero/invalid)
        $ma->write16Bit($buffer + 20, 0); // reserved
        $ma->writeBySize($buffer + 22, 0, 32); // host bus type ptr (none)
        // Note: buffer size is typically 0x1A (26 bytes), so we don't need more fields

        $ma->writeToHighBit(RegisterType::EAX, 0x00);
        $ma->setCarryFlag(false);
    }

    private function segmentLinearAddress(RuntimeInterface $runtime, int $selector, int $offset, int $addressSize): int
    {
        $offsetMask = $addressSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $linearMask = $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        $linear = ((($selector << 4) & 0xFFFFF) + ($offset & $offsetMask)) & $linearMask;

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $limit = $gdtr['limit'] ?? 0;
            $index = ($selector >> 3) & 0x1FFF;
            $descAddr = $base + ($index * 8);

            if ($descAddr + 7 <= $base + $limit) {
                $b0 = $this->readMemory8($runtime, $descAddr + 2);
                $b1 = $this->readMemory8($runtime, $descAddr + 3);
                $b2 = $this->readMemory8($runtime, $descAddr + 4);
                $b7 = $this->readMemory8($runtime, $descAddr + 7);

                $segBase = ($b0) | ($b1 << 8) | ($b2 << 16) | ($b7 << 24);
                $linear = ($segBase + ($offset & $offsetMask)) & $linearMask;
            }
        }

        $runtime->option()->logger()->debug(sprintf(
            'segmentLinearAddress: selector=0x%04X offset=0x%04X -> linear=0x%05X (addrSize=%d, pm=%d)',
            $selector,
            $offset,
            $linear,
            $addressSize,
            $runtime->context()->cpu()->isProtectedMode() ? 1 : 0
        ));

        return $linear;
    }

    private function writeSectorsCHS(RuntimeInterface $runtime, int $sectorsToWrite): void
    {
        if ($sectorsToWrite === 0) {
            $this->fail($runtime, 0x04);
            return;
        }

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
            $sectorsPerTrack = 18;
            $headsPerCylinder = 2;
        } else {
            $sectorsPerTrack = self::SECTORS_PER_TRACK;
            $headsPerCylinder = self::HEADS_PER_CYLINDER;
        }

        $lba = ($cylinder * $headsPerCylinder + $head) * $sectorsPerTrack + ($sector - 1);
        $bufferAddress = $this->segmentLinearAddress($runtime, $es, $bx, $addressSize);

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
        $dapLinear = $this->segmentLinearAddress($runtime, $ds, $si, $addressSize);

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

        if ($dl >= 0x80) {
            // Hard disk - return type 3
            $ma->writeToHighBit(RegisterType::EAX, 0x03);
            // CX:DX = number of 512-byte sectors
            $totalSectors = 0x0010_0000; // ~512MB
            $ma->write16Bit(RegisterType::ECX, ($totalSectors >> 16) & 0xFFFF);
            $ma->write16Bit(RegisterType::EDX, $totalSectors & 0xFFFF);
        } else {
            // Floppy or no drive
            $ma->writeToHighBit(RegisterType::EAX, 0x00);
        }

        $ma->setCarryFlag(false);
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
            $buffer = $this->segmentLinearAddress($runtime, $ds, $si, $addressSize);

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
}
