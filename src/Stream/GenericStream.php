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
        $data = fread($this->resource, 2);
        if ($data === false || strlen($data) < 2) {
            throw new StreamReaderException('Cannot read from stream or reached EOF');
        }
        return unpack('v', $data)[1]; // Little-endian unsigned short
    }

    public function signedShort(): int
    {
        $value = $this->short();
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function dword(): int
    {
        $data = fread($this->resource, 4);
        if ($data === false || strlen($data) < 4) {
            throw new StreamReaderException('Cannot read from stream or reached EOF');
        }
        return unpack('V', $data)[1]; // Little-endian unsigned long
    }

    public function signedDword(): int
    {
        $value = $this->dword();
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function qword(): int
    {
        $data = fread($this->resource, 8);
        if ($data === false || strlen($data) < 8) {
            throw new StreamReaderException('Cannot read from stream or reached EOF');
        }
        return unpack('P', $data)[1]; // Little-endian unsigned long long
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
