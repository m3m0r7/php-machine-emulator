<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Exception\StreamReaderException;

class FileStream implements FileStreamInterface
{
    use GenericStream;

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

    public function proxy(): StreamReaderProxyInterface
    {
        $streamReader = new self($this->path);
        $streamReader->setOffset($this->offset());

        return new StreamReaderProxy($streamReader);
    }

    public function fileSize(): int
    {
        return $this->fileSize;
    }

    public function path(): string
    {
        return $this->path;
    }
}

