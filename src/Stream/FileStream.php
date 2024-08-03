<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Exception\StreamReaderException;

class FileStream implements StreamReaderInterface
{
    /**
     * @var resource $resource
     */
    protected mixed $resource;

    /**
     * @var int $fileSize
     */
    protected int $fileSize;

    public function __construct(protected string $path)
    {
        $this->resource = fopen($path, 'r');

        if ($this->resource === false) {
            throw new StreamReaderException('Cannot open specified path');
        }

        $this->fileSize = filesize($this->path);

        if ($this->fileSize === false) {
            throw new StreamReaderException('Cannot calculate file size');
        }
    }

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
        $result = fseek($this->resource, $newOffset, \SEEK_SET);

        if ($result > 0) {
            throw new StreamReaderException('Cannot set the offset');
        }

        return $this;
    }

    public function isEOF(): bool
    {
        return $this->offset() === $this->fileSize || feof($this->resource);
    }
}

