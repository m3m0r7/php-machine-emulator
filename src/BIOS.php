<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use PHPMachineEmulator\Exception\BIOSInvalidException;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class BIOS
{
    public const NAME = 'PHPMachineEmulator';
    public const BIOS_ENTRYPOINT = 0x7C00;
    public const READ_SIZE_PER_SECTOR = 512;

    public function __construct(protected MachineInterface $machine)
    {
        if ($this->machine->logicBoard()->media()->primary()->bootType() === BootType::BOOT_SIGNATURE) {
            $this->verifyBIOSSignature();
        }
    }

    public function machine(): MachineInterface
    {
        return $this->machine;
    }

    public function runtime(): RuntimeInterface
    {
        return $this->machine->runtime(self::BIOS_ENTRYPOINT);
    }

    public static function start(MachineInterface $machine): void
    {
        try {
            (new static($machine))
                ->runtime()
                ->start();
        } catch (HaltException) {
            throw new ExitException('Halted', 0);
        } catch (ExitException $e) {
            throw $e;
        }
    }

    protected function verifyBIOSSignature(): void
    {
        $bootStream = $this->machine->logicBoard()->media()->primary()->stream();
        $proxy = $bootStream->proxy();
        try {
            $proxy->setOffset(510);
        } catch (StreamReaderException) {
            throw new BIOSInvalidException('The disk is invalid. Failed to change offsets');
        }

        $low = $proxy->byte();
        $high = $proxy->byte();

        if ($high !== 0xAA || $low !== 0x55) {
            throw new BIOSInvalidException('The BIOS signature is invalid');
        }
    }
}
