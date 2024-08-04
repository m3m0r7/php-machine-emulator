<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use PHPMachineEmulator\Exception\BIOSInvalidException;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Stream\StreamReaderIsProxyableInterface;

class BIOS extends Machine
{
    public const NAME = 'PHPMachineEmulator';
    public const BIOS_ENTRYPOINT = 0x7C00;

    public function __construct(StreamReaderIsProxyableInterface $streamReader, OptionInterface $option)
    {
        parent::__construct($streamReader, $option);

        $this->verifyBIOSSignature();
    }

    public static function start(StreamReaderIsProxyableInterface $streamReader, MachineType $useMachineType = MachineType::Intel_x86, OptionInterface $option = new Option()): void
    {
        try {
            (new static(
                $streamReader,
                $option,
            ))->runtime($useMachineType)
                ->start(static::BIOS_ENTRYPOINT);
        } catch (HaltException) {
            throw new ExitException('Halted', 0);
        } catch (ExitException $e) {
            throw $e;
        }
    }

    protected function verifyBIOSSignature(): void
    {
        $proxy = $this->streamReader->proxy();
        try {
            $proxy->setOffset(510);
        } catch (StreamReaderException) {
            throw new BIOSInvalidException('The disk is invalid');
        }

        $low = $proxy->byte();
        $high = $proxy->byte();

        if ($high !== 0xAA || $low !== 0x55 || !$proxy->isEOF()) {
            throw new BIOSInvalidException('The disk is invalid');
        }
    }
}
