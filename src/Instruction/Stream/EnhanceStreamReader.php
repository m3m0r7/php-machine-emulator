<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Stream;

use PHPMachineEmulator\Stream\StreamReaderInterface;

class EnhanceStreamReader
{
    public function __construct(protected StreamReaderInterface $streamReader)
    {
    }

    public function byteAsSIB(): SIBInterface
    {
        return new SIB($this->streamReader->byte());
    }


    public function byteAsModRegRM(): ModRegRMInterface
    {
        return new ModRegRM($this->streamReader->byte());
    }

    public function signedShort(): int
    {
        $operand1 = $this->streamReader->byte();
        $operand2 = $this->streamReader->byte();

        $value = ($operand2 << 8) + $operand1;
        return $value >= 0x8000
            ? $value - 0x10000
            : $value;
    }
}
