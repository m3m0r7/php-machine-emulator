<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Stream;

use PHPMachineEmulator\Stream\StreamReaderInterface;

class SIB implements SIBInterface
{
    /** @var array<int, SIB> */
    private static array $cache = [];

    public function __construct(protected int $value)
    {
    }

    /**
     * Get a cached SIB instance for the given byte value.
     */
    public static function fromByte(int $byte): self
    {
        return self::$cache[$byte] ??= new self($byte);
    }

    public function scale(): int
    {
        return ($this->value >> 6) & 0b00000011;
    }

    public function base(): int
    {
        return $this->value & 0b00000111;
    }

    public function index(): int
    {
        return ($this->value >> 3) & 0b00000111;
    }
}
