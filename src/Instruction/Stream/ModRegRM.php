<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Stream;

use PHPMachineEmulator\Stream\StreamReaderInterface;

class ModRegRM implements ModRegRMInterface
{
    /** @var array<int, ModRegRM> */
    private static array $cache = [];

    public function __construct(protected int $value)
    {
    }

    /**
     * Get a cached ModRegRM instance for the given byte value.
     * This avoids creating new objects for each instruction.
     */
    public static function fromByte(int $byte): self
    {
        return self::$cache[$byte] ??= new self($byte);
    }

    public function mode(): int
    {
        return ($this->value >> 6) & 0b00000011;
    }

    public function source(): int
    {
        return $this->value & 0b00000111;
    }

    public function registerOrMemoryAddress(): int
    {
        // NOTE: same at `::source`
        return $this->source();
    }

    public function destination(): int
    {
        return ($this->value >> 3) & 0b00000111;
    }

    public function digit(): int
    {
        // NOTE: same at `::destination`
        return $this->destination();
    }

    public function registerOrOPCode(): int
    {
        // NOTE: same at `::destination`
        return $this->destination();
    }
}
