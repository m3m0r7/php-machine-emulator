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

        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asByte();
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
        $bufferAddress = (($es << 4) + $bx) & 0xFFFFF;

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
