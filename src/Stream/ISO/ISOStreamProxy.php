<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

use PHPMachineEmulator\Stream\StreamReaderInterface;
use PHPMachineEmulator\Stream\StreamReaderProxyInterface;

class ISOStreamProxy implements StreamReaderProxyInterface
{
    private int $offset = 0;

    public function __construct(private ISOStream $stream)
    {
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $newOffset): StreamReaderInterface
    {
        $this->offset = $newOffset;
        return $this->stream;
    }

    public function isEOF(): bool
    {
        return $this->offset >= $this->stream->fileSize();
    }

    public function char(): string
    {
        $this->stream->setOffset($this->offset);
        $char = $this->stream->char();
        $this->offset = $this->stream->offset();
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

    public function readBytes(int $length): string
    {
        $this->stream->setOffset($this->offset);
        $data = $this->stream->readBytes($length);
        $this->offset = $this->stream->offset();
        return $data;
    }

    public function isoStream(): ISOStream
    {
        return $this->stream;
    }
}
