<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Exception\BIOSInvalidException;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Runtime\RuntimeOption;
use PHPMachineEmulator\Stream\BootableStreamInterface;

class BIOS extends Machine
{
    public const NAME = 'PHPMachineEmulator';
    public const BIOS_ENTRYPOINT = 0x7C00;
    public const READ_SIZE_PER_SECTOR = 512;

    public function __construct(BootableStreamInterface $bootStream, OptionInterface $option)
    {
        parent::__construct($bootStream, $option);

        if ($option->bootType() === BootType::BOOT_SIGNATURE) {
            $this->verifyBIOSSignature();
        }
    }

    public static function start(BootableStreamInterface $bootStream, MachineType $useMachineType = MachineType::Intel_x86, OptionInterface $option = new Option()): void
    {
        try {
            (new static(
                $bootStream,
                $option,
            ))->runtime($useMachineType)
                ->start();
        } catch (HaltException) {
            throw new ExitException('Halted', 0);
        } catch (ExitException $e) {
            throw $e;
        }
    }

    protected function verifyBIOSSignature(): void
    {
        $proxy = $this->bootableStream->proxy();
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

    protected function createRuntime(ArchitectureProviderInterface $architectureProvider): RuntimeInterface
    {
        return new ($this->option->runtimeClass())(
            $this,
            new RuntimeOption(self::BIOS_ENTRYPOINT),
            $architectureProvider,
            $this->bootableStream,
        );
    }
}
