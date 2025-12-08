<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Exception\StreamReaderException;

trait GenericStream
{
    public function char(): string
    {
        $char = fread($this->resource, 1);

        if ($char === false || $char === '') {
            throw new StreamReaderException('Cannot read from stream or reached EOF');
        }
        return $char;
    }

    public function byte(): int
    {
        return unpack('C', $this->char())[1];
    }

    public function signedByte(): int
    {
        return unpack('c', $this->char())[1];
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
        $offset = ftell($this->resource);

        if ($offset === false) {
            throw new StreamReaderException('Cannot get the offset');
        }

        return $offset;
    }

    public function setOffset(int $newOffset): self
    {
        if ($newOffset > $this->fileSize) {
            throw new StreamReaderException('Cannot set the offset');
        }
        $result = fseek($this->resource, $newOffset, \SEEK_SET);

        if ($result > 0) {
            throw new StreamReaderException('Cannot set the offset');
        }
        if ($this->isEOF()) {
            throw new StreamReaderException('Cannot set the offset because the stream is reached EOF');
        }

        return $this;
    }

    public function isEOF(): bool
    {
        return $this->offset() === $this->fileSize || feof($this->resource);
    }

    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $data = fread($this->resource, $length);
        return $data === false ? '' : $data;
    }
}
