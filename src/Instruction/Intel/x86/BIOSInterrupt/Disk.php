<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Disk\HardDisk;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Exception\StreamReaderException;

class Disk implements InterruptInterface
{
    private const SECTOR_SIZE = BIOS::READ_SIZE_PER_SECTOR;
    private const SECTORS_PER_TRACK = 63;
    private const HEADS_PER_CYLINDER = 16;

    public function __construct(protected RuntimeInterface $runtime)
    {
    }

    public function process(RuntimeInterface $runtime): void
    {
        $runtime->option()->logger()->debug('Reached to disk interruption');

        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        $ah = $ax->asHighBit();    // AH
        $al = $ax->asLowBit();   // AL

        match ($ah) {
            0x00 => $this->reset($runtime),
            0x02 => $this->readSectorsCHS($runtime, $al),
            0x41 => $this->extensionsPresent($runtime),
            0x42 => $this->readSectorsLBA($runtime),
            0x48 => $this->getDriveParametersExtended($runtime),
            0x08 => $this->getDriveParameters($runtime),
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
        $dapLinear = $this->segmentLinearAddress($runtime, $ds, $si, $addressSize);

        $size = $this->readMemory8($runtime, $dapLinear);
        if ($size < 16) {
            $this->fail($runtime, 0x01);
            return;
        }

        $sectorCount = $this->readMemory16($runtime, $dapLinear + 2);
        $lbaLow = $this->readMemory32($runtime, $dapLinear + 4);
        $lbaHigh = $this->readMemory32($runtime, $dapLinear + 8); // unused but parsed
        $bufferOffset = $this->readMemory16($runtime, $dapLinear + 12);
        $bufferSegment = $this->readMemory16($runtime, $dapLinear + 14);

        if ($sectorCount === 0) {
            $this->fail($runtime, 0x04);
            return;
        }

        $bufferAddress = $this->segmentLinearAddress($runtime, $bufferSegment, $bufferOffset, $addressSize);
        $lba = $lbaLow; // high part ignored in this simplified model
        $bytes = $sectorCount * self::SECTOR_SIZE;

        $reader = $runtime->streamReader()->proxy();
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
                $b0 = $runtime->memoryAccessor()->tryToFetch($descAddr + 2)?->asHighBit() ?? 0;
                $b1 = $runtime->memoryAccessor()->tryToFetch($descAddr + 3)?->asHighBit() ?? 0;
                $b2 = $runtime->memoryAccessor()->tryToFetch($descAddr + 4)?->asHighBit() ?? 0;
                $b7 = $runtime->memoryAccessor()->tryToFetch($descAddr + 7)?->asHighBit() ?? 0;

                $segBase = ($b0) | ($b1 << 8) | ($b2 << 16) | ($b7 << 24);
                return ($segBase + ($offset & $offsetMask)) & $linearMask;
            }
        }

        return ((($selector << 4) & 0xFFFFF) + ($offset & $offsetMask)) & $linearMask;
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
}
