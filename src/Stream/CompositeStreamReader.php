<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Composite stream reader that reads from boot stream or memory based on current offset.
 * Automatically switches based on address - no manual mode switching needed.
 */
class CompositeStreamReader implements StreamReaderIsProxyableInterface
{
    private int $offset = 0;

    public function __construct(
        private readonly RuntimeInterface $runtime,
        private readonly StreamReaderIsProxyableInterface $bootStream,
        private readonly StreamReaderIsProxyableInterface $memoryStream,
        private readonly int $bootStreamSize,
    ) {}


    public function char(): string
    {
        // Once memory mode is activated, always use memory stream
        if ($this->runtime->context()->cpu()->isMemoryMode()) {
            $this->memoryStream->setOffset($this->offset);
            $char = $this->memoryStream->char();
            $this->offset++;
            return $char;
        }

        // If within boot image, read from boot stream
        if ($this->offset < $this->bootStreamSize) {
            $this->bootStream->setOffset($this->offset);
            $char = $this->bootStream->char();
            $this->offset++;
            return $char;
        }

        // Beyond boot image, use memory stream
        $this->memoryStream->setOffset($this->offset);
        $char = $this->memoryStream->char();
        $this->offset++;
        return $char;
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

    public function offset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $newOffset): self
    {
        // If jumping to high memory address (beyond boot area),
        // activate memory mode permanently - this means we're executing
        // dynamically loaded code (e.g., kernel loaded via INT 13h)
        $this->runtime->context()->cpu()->activateMemoryModeIfNeeded($newOffset);

        $this->offset = $newOffset;
        return $this;
    }

    public function isEOF(): bool
    {
        // Never EOF when memory stream is available
        return false;
    }

    public function proxy(): StreamReaderProxyInterface
    {
        // For proxy, sync offset and return boot stream's proxy
        $this->bootStream->setOffset(min($this->offset, $this->bootStreamSize - 1));
        return $this->bootStream->proxy();
    }

    public function bootStream(): StreamReaderIsProxyableInterface
    {
        return $this->bootStream;
    }
}
