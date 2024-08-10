<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Disk\HardDisk;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Disk implements InterruptInterface
{
    public function __construct(protected RuntimeInterface $runtime)
    {
    }

    public function process(RuntimeInterface $runtime): void
    {
        $runtime->option()->logger()->debug('Reached to disk interruption');

        $command = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EAX)
            ->asHighBit();

        if ($command !== 0x02) {
            $runtime
                ->option()
                ->logger()
                ->error('In currently to support only reading sector');

            $runtime
                ->memoryAccessor()
                ->setCarryFlag(true);
            return;
        }

        $readingSectors = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EAX)
            ->asLowBitChar();

        $targetOffset = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EBX)
            ->asByte();

        $trackNumber = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::ECX)
            ->asHighBit();

        $sectorNumber = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::ECX)
            ->asLowBit();

        $headNumber = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EDX)
            ->asHighBit();

        $driveNumber = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EDX)
            ->asLowBit();

        if ($driveNumber < 0x80) {
            $runtime
                ->option()
                ->logger()
                ->error('In currently to support only a Hard Disk drive');

            $runtime
                ->memoryAccessor()
                ->setCarryFlag(true);
            return;
        }

        $runtime
            ->addressMap()
            ->register(
                $targetOffset,
                new HardDisk(
                    $driveNumber,
                    (($sectorNumber - 1) * BIOS::READ_SIZE_PER_SECTOR) + $headNumber,
                    (($sectorNumber - 1) * BIOS::READ_SIZE_PER_SECTOR),
                ),
            );

        // NOTE: Set carry flag to zero
        $runtime
            ->memoryAccessor()
            ->setCarryFlag(false);
    }
}
