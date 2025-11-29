<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

/**
 * Proxy for streams that implement both read and write interfaces.
 */
class StreamProxy implements StreamProxyInterface
{
    public function __construct(protected StreamIsProxyableInterface $stream)
    {
    }

    // ========================================
    // StreamReaderInterface implementation
    // ========================================

    public function offset(): int
    {
        return $this->stream->offset();
    }

    public function setOffset(int $newOffset): StreamReaderInterface
    {
        return $this->stream->setOffset($newOffset);
    }

    public function isEOF(): bool
    {
        return $this->stream->isEOF();
    }

    public function char(): string
    {
        return $this->stream->char();
    }

    public function byte(): int
    {
        return $this->stream->byte();
    }

    public function signedByte(): int
    {
        return $this->stream->signedByte();
    }

    public function short(): int
    {
        return $this->stream->short();
    }

    public function dword(): int
    {
        return $this->stream->dword();
    }

    // ========================================
    // StreamWriterInterface implementation
    // ========================================

    public function write(string $value): StreamWriterInterface
    {
        return $this->stream->write($value);
    }

    public function writeByte(int $value): void
    {
        $this->stream->writeByte($value);
    }

    public function writeShort(int $value): void
    {
        $this->stream->writeShort($value);
    }

    public function writeDword(int $value): void
    {
        $this->stream->writeDword($value);
    }

    public function copy(StreamReaderInterface $source, int $sourceOffset, int $destOffset, int $size): void
    {
        $this->stream->copy($source, $sourceOffset, $destOffset, $size);
    }
}
