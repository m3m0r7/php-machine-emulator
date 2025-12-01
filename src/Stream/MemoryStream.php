<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

/**
 * Unified memory stream backed by php://temp.
 *
 * Uses php://temp/maxmemory:xxx to allow large memory with automatic
 * swap to temporary file when exceeding the threshold.
 *
 * Implements both read and write operations on a single memory space.
 * Boot data is copied here at startup, and all subsequent operations
 * (INT 13h disk reads, REP MOVSB, instruction fetch, etc.) work on this same memory.
 */
class MemoryStream implements StreamIsProxyableInterface, StreamReaderIsProxyableInterface, StreamIsCopyableInterface
{
    /** @var resource */
    private $memory;

    private int $offset = 0;

    private int $size;

    private int $maxSize;

    /**
     * @param int $size Initial memory size (default 1MB)
     * @param int $maxSize Maximum memory size for auto-expansion (default 16MB)
     * @param int $tempMaxMemory Maximum bytes to keep in memory before swapping to temp file (default 256MB)
     */
    public function __construct(int $size = 0x100000, int $maxSize = 0x1000000, int $tempMaxMemory = 0x10000000)
    {
        $this->size = $size;
        $this->maxSize = $maxSize;

        // Use php://temp with maxmemory option
        // Data is kept in memory up to $tempMaxMemory bytes, then swaps to temp file
        $this->memory = fopen("php://temp/maxmemory:{$tempMaxMemory}", 'r+b');

        // Pre-allocate memory with zeros
        fwrite($this->memory, str_repeat("\x00", $size));
        rewind($this->memory);
    }

    /**
     * Expand memory if needed to accommodate the given offset.
     */
    public function ensureCapacity(int $requiredOffset): bool
    {
        if ($requiredOffset < $this->size) {
            return true;
        }

        if ($requiredOffset >= $this->maxSize) {
            return false;
        }

        // Expand in 1MB chunks
        $newSize = min(
            $this->maxSize,
            (int) ceil(($requiredOffset + 1) / 0x100000) * 0x100000
        );

        $currentPos = ftell($this->memory);
        fseek($this->memory, $this->size, SEEK_SET);
        fwrite($this->memory, str_repeat("\x00", $newSize - $this->size));
        fseek($this->memory, $currentPos, SEEK_SET);

        $this->size = $newSize;
        return true;
    }

    // ========================================
    // StreamReaderInterface implementation
    // ========================================

    public function char(): string
    {
        // Safety check: don't allow access beyond maxSize
        if ($this->offset >= $this->maxSize) {
            throw new \RuntimeException(sprintf('Memory read out of bounds: offset=0x%X maxSize=0x%X', $this->offset, $this->maxSize));
        }

        // Auto-expand if reading beyond current size
        if ($this->offset >= $this->size) {
            $this->ensureCapacity($this->offset);
        }

        fseek($this->memory, $this->offset, SEEK_SET);
        $char = fread($this->memory, 1);
        $this->offset++;

        return $char === false || $char === '' ? "\x00" : $char;
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
        // Safety check: don't allow setting offset beyond maxSize
        if ($newOffset < 0 || $newOffset >= $this->maxSize) {
            throw new \RuntimeException(sprintf('Cannot set offset beyond bounds: offset=0x%X maxSize=0x%X', $newOffset, $this->maxSize));
        }

        // Auto-expand memory if needed
        if ($newOffset >= $this->size) {
            $this->ensureCapacity($newOffset);
        }
        $this->offset = $newOffset;
        return $this;
    }

    public function isEOF(): bool
    {
        return $this->offset >= $this->size && $this->offset >= $this->maxSize;
    }

    // ========================================
    // StreamWriterInterface implementation
    // ========================================

    public function write(string $value): self
    {
        $endOffset = $this->offset + strlen($value);
        if ($endOffset >= $this->size) {
            $this->ensureCapacity($endOffset);
        }

        fseek($this->memory, $this->offset, SEEK_SET);
        fwrite($this->memory, $value);
        $this->offset += strlen($value);
        return $this;
    }

    public function writeByte(int $value): void
    {
        // Safety check: don't allow access beyond maxSize
        if ($this->offset >= $this->maxSize) {
            throw new \RuntimeException(sprintf('Memory access out of bounds: offset=0x%X maxSize=0x%X', $this->offset, $this->maxSize));
        }

        if ($this->offset >= $this->size) {
            $this->ensureCapacity($this->offset);
        }

        fseek($this->memory, $this->offset, SEEK_SET);
        fwrite($this->memory, chr($value & 0xFF));
        $this->offset++;
    }

    public function writeShort(int $value): void
    {
        $this->writeByte($value & 0xFF);
        $this->writeByte(($value >> 8) & 0xFF);
    }

    public function writeDword(int $value): void
    {
        $this->writeByte($value & 0xFF);
        $this->writeByte(($value >> 8) & 0xFF);
        $this->writeByte(($value >> 16) & 0xFF);
        $this->writeByte(($value >> 24) & 0xFF);
    }

    public function copy(StreamReaderInterface $source, int $sourceOffset, int $destOffset, int $size): void
    {
        // Save current positions
        $originalSourceOffset = $source->offset();
        $originalDestOffset = $this->offset;

        // Set source position
        $source->setOffset($sourceOffset);

        // Set destination position
        fseek($this->memory, $destOffset, SEEK_SET);

        // Copy byte by byte
        for ($i = 0; $i < $size; $i++) {
            $byte = $source->byte();
            fwrite($this->memory, chr($byte));
        }

        // Restore positions
        $source->setOffset($originalSourceOffset);
        $this->offset = $originalDestOffset;
    }

    // ========================================
    // Proxyable interface implementation
    // ========================================

    public function proxy(): StreamProxyInterface
    {
        return new StreamProxy($this);
    }

    public function size(): int
    {
        return $this->size;
    }
}
