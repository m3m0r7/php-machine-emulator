<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Util\BinaryInteger;

class MemoryAccessorFetchResult implements MemoryAccessorFetchResultInterface
{
    public function __construct(protected int|null $value)
    {
    }

    public function asChar(): string
    {
        if ($this->value === null) {
            return chr(0);
        }
        return chr($this->value);
    }

    public function asLowBitChar(): string
    {
        if ($this->value === null) {
            return chr(0);
        }
        return chr($this->asLowBit());
    }

    public function asHighBitChar(): string
    {
        if ($this->value === null) {
            return chr(0);
        }
        return chr($this->asHighBit());
    }

    public function asByte(): int
    {
        return $this->asBytesBySize(16);
    }

    public function asBytesBySize(int $size = 64): int
    {
        if ($this->value === null) {
            return 0;
        }
        return BinaryInteger::asLittleEndian(
            $this->value,
            $size,
        );
    }

    public function asLowBit(): int
    {
        return ($this->value >> 8) & 0b11111111;
    }

    public function asHighBit(): int
    {
        return $this->value & 0b11111111;
    }

    public function valueOf(): int|null
    {
        return $this->value;
    }
}
