<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

class StreamReaderProxy implements StreamReaderProxyInterface
{
    public function __construct(protected StreamReaderInterface $stream)
    {
    }

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
}
