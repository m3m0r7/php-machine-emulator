<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Runtime\MemoryAccessorInterface;

/**
 * Stream reader that reads from emulated memory.
 * Used when executing code loaded dynamically at runtime (e.g., kernel loaded via INT 13h).
 */
class MemoryStreamReader implements StreamReaderIsProxyableInterface
{
    private int $offset = 0;

    public function __construct(
        private readonly MemoryAccessorInterface $memoryAccessor,
    ) {}

    public function char(): string
    {
        // Read byte from memory at the given linear address
        // Use tryToFetch + asHighBit for compatibility with how memory is stored
        $result = $this->memoryAccessor->tryToFetch($this->offset);
        $this->offset++;

        if ($result === null) {
            // Memory not allocated - return 0 (like uninitialized RAM)
            return chr(0);
        }

        // Return high byte of the value (matches original ISOStream memory reader behavior)
        return chr($result->asHighBit() & 0xFF);
    }

    public function byte(): int
    {
        return ord($this->char());
    }

    public function signedByte(): int
    {
        $byte = $this->byte();
        return $byte > 127 ? $byte - 256 : $byte;
    }

    public function short(): int
    {
        $low = $this->byte();
        $high = $this->byte();
        return $low | ($high << 8);
    }

    public function dword(): int
    {
        $b0 = $this->byte();
        $b1 = $this->byte();
        $b2 = $this->byte();
        $b3 = $this->byte();
        return $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $newOffset): self
    {
        $this->offset = $newOffset;
        return $this;
    }

    public function isEOF(): bool
    {
        // Memory is always readable
        return false;
    }

    public function proxy(): StreamReaderProxyInterface
    {
        return new StreamReaderProxy($this);
    }
}
