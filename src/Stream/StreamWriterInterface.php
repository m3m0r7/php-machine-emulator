<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

interface StreamWriterInterface
{
    public function write(string $value): self;

    /**
     * Write a single byte at current offset.
     */
    public function writeByte(int $value): void;

    /**
     * Write a 16-bit value (little-endian) at current offset.
     */
    public function writeShort(int $value): void;

    /**
     * Write a 32-bit value (little-endian) at current offset.
     */
    public function writeDword(int $value): void;
}
