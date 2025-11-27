<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Stream\StreamReaderInterface;
use PHPMachineEmulator\Stream\StreamReaderIsProxyableInterface;
use PHPMachineEmulator\Stream\StreamReaderProxy;
use PHPMachineEmulator\Stream\StreamReaderProxyInterface;

class ISOStream implements StreamReaderIsProxyableInterface
{
    private ISO9660 $iso;
    private ?ElTorito $elTorito = null;
    private ?BootImage $bootImage = null;
    private int $offset = 0;
    private int $fileSize;
    private string $bootData;

    public function __construct(private string $path)
    {
        $this->iso = new ISO9660($path);

        if (!$this->iso->hasElTorito()) {
            throw new StreamReaderException('ISO does not contain El Torito boot record');
        }

        $bootRecord = $this->iso->bootRecord();
        $this->elTorito = new ElTorito($this->iso, $bootRecord->bootCatalogSector);

        $this->bootImage = $this->elTorito->getBootImage();

        if ($this->bootImage === null) {
            throw new StreamReaderException('No bootable image found in ISO');
        }

        $this->bootData = $this->bootImage->data();
        $this->fileSize = strlen($this->bootData);
    }

    public function iso(): ISO9660
    {
        return $this->iso;
    }

    public function elTorito(): ?ElTorito
    {
        return $this->elTorito;
    }

    public function bootImage(): ?BootImage
    {
        return $this->bootImage;
    }

    public function char(): string
    {
        if ($this->offset >= $this->fileSize) {
            throw new StreamReaderException('Cannot read from stream or reached EOF');
        }

        $char = $this->bootData[$this->offset];
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
        if ($newOffset > $this->fileSize) {
            throw new StreamReaderException('Cannot set the offset beyond file size');
        }

        $this->offset = $newOffset;

        return $this;
    }

    public function isEOF(): bool
    {
        return $this->offset >= $this->fileSize;
    }

    public function proxy(): StreamReaderProxyInterface
    {
        $proxy = new ISOStreamProxy($this);
        $proxy->setOffset($this->offset);

        return $proxy;
    }

    public function readBytes(int $length): string
    {
        if ($this->offset >= $this->fileSize) {
            return '';
        }

        $data = substr($this->bootData, $this->offset, $length);
        $this->offset += strlen($data);

        return $data;
    }

    public function fileSize(): int
    {
        return $this->fileSize;
    }
}
