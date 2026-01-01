<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Exception\StreamReaderException;

class ResourceWriterStream implements StreamWriterInterface
{
    /**
     * @var resource $resource
     */
    protected mixed $resource;

    public function __construct($resource)
    {
        $this->resource = $resource;

        if (!is_resource($this->resource)) {
            throw new StreamReaderException('The parameter is not a resource');
        }
    }

    public function write(string $value): StreamWriterInterface
    {
        fwrite($this->resource, $value);
        return $this;
    }

    public function writeByte(int $value): void
    {
        fwrite($this->resource, chr($value & 0xFF));
    }

    public function writeShort(int $value): void
    {
        fwrite($this->resource, pack('v', $value & 0xFFFF));
    }

    public function writeDword(int $value): void
    {
        fwrite($this->resource, pack('V', $value & 0xFFFFFFFF));
    }
}
