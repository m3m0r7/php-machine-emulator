<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Disk\HardDisk;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Stream\ISO\ISOStream;
use PHPMachineEmulator\Stream\ISO\ISOStreamProxy;
use PHPMachineEmulator\Stream\ISO\ISO9660;

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

        $runtime->option()->logger()->debug(sprintf('Disk interrupt: AH=0x%02X, AL=0x%02X', $ah, $al));

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
            0x4B => $this->terminateDiskEmulation($runtime),
            default => $this->unsupported($runtime, $ah),
        };
    }

    private function reset(RuntimeInterface $runtime): void
    {
        // BIOS reset simply clears errors/carry.
        $runtime->memoryAccessor()->setCarryFlag(false);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, 0x00);
    }

    private function getDriveParameters(RuntimeInterface $runtime): void
    {
        $heads = self::HEADS_PER_CYLINDER;
        $sectors = self::SECTORS_PER_TRACK;
        $cylinders = 1024; // generic fallback geometry

        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, 0x00); // AH = 0 (success)
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToLowBit(RegisterType::EAX, $sectors); // AL = sectors per track (approx)

        $cl = ($sectors & 0x3F) | ((($cylinders >> 8) & 0x03) << 6);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToLowBit(RegisterType::ECX, $cl);           // CL
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::ECX, $cylinders);    // CH

        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EDX, $heads - 1);    // DH
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToLowBit(RegisterType::EDX, 0x01);         // DL

        $runtime->memoryAccessor()->setCarryFlag(false);
    }

    private function readSectorsCHS(RuntimeInterface $runtime, int $sectorsToRead): void
    {
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

        if ($dl < 0x80) {
            $this->fail($runtime, 0x01); // invalid function for drive
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

        $lba = ($cylinder * self::HEADS_PER_CYLINDER + $head) * self::SECTORS_PER_TRACK + ($sector - 1);
        $bytes = $sectorsToRead * self::SECTOR_SIZE;
        $bufferAddress = $this->segmentLinearAddress($runtime, $es, $bx, $addressSize);

        $reader = $runtime->streamReader()->proxy();

        try {
            $reader->setOffset($lba * self::SECTOR_SIZE);
        } catch (StreamReaderException) {
            $this->fail($runtime, 0x20); // controller failure
            return;
        }

        for ($i = 0; $i < $bytes; $i++) {
            try {
                $byte = $reader->byte();
            } catch (StreamReaderException) {
                $this->fail($runtime, 0x20);
                return;
            }

            $address = $bufferAddress + $i;
            $runtime->memoryAccessor()->allocate($address, safe: false);
            $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($address, $byte, 8);
        }

        // update AL with sectors read, AH = 0, clear CF
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, 0x00);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToLowBit(RegisterType::EAX, $sectorsToRead);
        $runtime->memoryAccessor()->setCarryFlag(false);

        // Track mapping for later addressMap lookups (best-effort)
        $runtime->addressMap()->register(
            max(0, $bufferAddress - 1),
            new HardDisk($dl, $lba * self::SECTOR_SIZE, $lba * self::SECTOR_SIZE),
        );
    }

    private function extensionsPresent(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $ma->writeToHighBit(RegisterType::EAX, 0x00); // AH = 0 on success
        $ma->write16Bit(RegisterType::BX, 0xAA55);
        $ma->setCarryFlag(false);
    }

    private function readSectorsLBA(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);

        // Many boot loaders set up the DAP at a fixed location using SI as an absolute address.
        // When DS changes during boot, using DS:SI would point to the wrong location.
        // We use SI directly as the linear DAP address to match common bootloader behavior.
        $dapLinear = $si;

        $runtime->option()->logger()->debug(sprintf(
            'LBA: DS=0x%04X, SI=0x%04X, DAP linear=0x%05X (using SI directly)',
            $ds, $si, $dapLinear
        ));


        // Read DAP size using byte-addressable memory
        $size = $this->readMemory8($runtime, $dapLinear);

        if ($size < 16) {
            $runtime->option()->logger()->debug(sprintf(
                'LBA: DAP size too small (%d) at 0x%05X, failing',
                $size, $dapLinear
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

        $runtime->option()->logger()->debug(sprintf(
            'LBA raw: sectorCount=0x%04X, bufOff=0x%04X, bufSeg=0x%04X, lba=0x%08X',
            $sectorCount, $bufferOffset, $bufferSegment, $lba
        ));

        // Sanity check: sector count should be reasonable (max 127 sectors is standard for BIOS)
        // Also check if DAP looks corrupted by verifying sectorCount is reasonable
        if ($sectorCount === 0 || $sectorCount > 127) {
            $runtime->option()->logger()->debug(sprintf(
                'LBA: invalid DAP - sectorCount=%d (expected 1-127), failing with error',
                $sectorCount
            ));
            $this->fail($runtime, 0x01); // Invalid function/parameter
            return;
        }

        $runtime->option()->logger()->debug(sprintf(
            'LBA: sectorCount=%d, bufferSeg:Off=0x%04X:0x%04X, LBA=%d',
            $sectorCount, $bufferSegment, $bufferOffset, $lba
        ));

        if ($sectorCount === 0) {
            $runtime->option()->logger()->debug('LBA: sectorCount is 0, failing');
            $this->fail($runtime, 0x04);
            return;
        }

        $bufferAddress = $this->segmentLinearAddress($runtime, $bufferSegment, $bufferOffset, $addressSize);

        $reader = $runtime->streamReader()->proxy();

        $runtime->option()->logger()->debug(sprintf(
            'LBA read: proxy type=%s',
            get_class($reader)
        ));

        // Check if this is an ISO stream - use CD sector size (2048 bytes)
        if ($reader instanceof ISOStreamProxy) {
            $runtime->option()->logger()->debug(sprintf(
                'LBA read from ISO: sector=%d, count=%d, buffer=0x%05X',
                $lba, $sectorCount, $bufferAddress
            ));

            // CD-ROM uses 2048-byte sectors
            $data = $reader->readCDSectors($lba, $sectorCount);
            $bytes = strlen($data);

            for ($i = 0; $i < $bytes; $i++) {
                $address = $bufferAddress + $i;
                $runtime->memoryAccessor()->allocate($address, safe: false);
                $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($address, ord($data[$i]), 8);
            }

            $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, 0x00);
            $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToLowBit(RegisterType::EAX, $sectorCount);
            $runtime->memoryAccessor()->setCarryFlag(false);
            return;
        }

        // Standard disk - use 512-byte sectors
        $bytes = $sectorCount * self::SECTOR_SIZE;

        try {
            $reader->setOffset($lba * self::SECTOR_SIZE);
        } catch (StreamReaderException) {
            $this->fail($runtime, 0x20);
            return;
        }

        for ($i = 0; $i < $bytes; $i++) {
            try {
                $byte = $reader->byte();
            } catch (StreamReaderException) {
                $this->fail($runtime, 0x20);
                return;
            }

            $address = $bufferAddress + $i;
            $runtime->memoryAccessor()->allocate($address, safe: false);
            $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($address, $byte, 8);
        }

        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, 0x00);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToLowBit(RegisterType::EAX, $sectorCount);
        $runtime->memoryAccessor()->setCarryFlag(false);
    }

    private function getDriveParametersExtended(RuntimeInterface $runtime): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($addressSize);
        $buffer = $this->segmentLinearAddress($runtime, $ds, $si, $addressSize);

        $cylinders = 1024;
        $heads = self::HEADS_PER_CYLINDER;
        $sectors = self::SECTORS_PER_TRACK;
        $totalSectors = 0x0010_0000; // ~512MB worth

        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);

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
        $ma->write16Bit($buffer + 18, self::SECTOR_SIZE);
        // EDD configuration params (set to zero/invalid)
        $ma->write16Bit($buffer + 20, 0); // reserved
        $ma->write32Bit($buffer + 22, 0); // host bus type ptr (none)
        $ma->write32Bit($buffer + 26, 0); // iface type ptr (none)
        $ma->write64Bit($buffer + 30, 0); // I/O ports / legacy base
        $ma->write64Bit($buffer + 38, 0); // legacy CHS info
        $ma->write32Bit($buffer + 46, 0); // checksum etc.

        $ma->writeToHighBit(RegisterType::EAX, 0x00);
        $ma->setCarryFlag(false);
    }

    private function segmentLinearAddress(RuntimeInterface $runtime, int $selector, int $offset, int $addressSize): int
    {
        $offsetMask = $addressSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $linearMask = $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;

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
                return ($segBase + ($offset & $offsetMask)) & $linearMask;
            }
        }

        return ((($selector << 4) & 0xFFFFF) + ($offset & $offsetMask)) & $linearMask;
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

        if ($dl < 0x80) {
            $this->fail($runtime, 0x01);
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

        $lba = ($cylinder * self::HEADS_PER_CYLINDER + $head) * self::SECTORS_PER_TRACK + ($sector - 1);
        $bufferAddress = $this->segmentLinearAddress($runtime, $es, $bx, $addressSize);

        // Write is a no-op for read-only media, but we accept the data
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, 0x00);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToLowBit(RegisterType::EAX, $sectorsToWrite);
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
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, 0x00);
        $runtime->memoryAccessor()->setCarryFlag(false);
    }

    private function getDiskType(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);

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

    private function terminateDiskEmulation(RuntimeInterface $runtime): void
    {
        // El Torito: Terminate disk emulation (INT 13h AH=4Bh)
        // AL = 00h: Terminate and return boot catalog
        // AL = 01h: Terminate and return boot catalog only if emulation active
        $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);

        // For now, just report no emulation active
        $ma->writeToHighBit(RegisterType::EAX, 0x00); // Success
        $ma->setCarryFlag(false);
    }

    private function unsupported(RuntimeInterface $runtime, int $command): void
    {
        $runtime->option()->logger()->error(sprintf('Disk interrupt command 0x%02X not supported yet', $command));
        $this->fail($runtime, 0x01);
    }

    private function fail(RuntimeInterface $runtime, int $status): void
    {
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, $status);
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

        // Try to read from stream
        $proxy = $runtime->streamReader()->proxy();
        $currentOffset = $proxy->offset();
        try {
            $origin = $runtime->addressMap()->getOrigin();
            if ($address >= $origin) {
                $proxy->setOffset($address - $origin);
                $byte = $proxy->byte();
                $proxy->setOffset($currentOffset);
                return $byte;
            }
        } catch (\Throwable) {
        }
        return 0;
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
