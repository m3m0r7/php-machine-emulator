<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Stream\BootImageInterface;
use PHPMachineEmulator\Stream\BootableStreamInterface;
use PHPMachineEmulator\Stream\StreamReaderInterface;
use PHPMachineEmulator\Stream\StreamReaderProxy;
use PHPMachineEmulator\Stream\StreamReaderProxyInterface;

class TestBootableStream implements BootableStreamInterface
{
    private int $offset = 0;
    private string $data;

    public function __construct(string $data = '')
    {
        $this->data = $data ?: str_repeat("\x00", 512);
    }

    public function proxy(): StreamReaderProxyInterface
    {
        $proxy = new self($this->data);
        $proxy->setOffset($this->offset());
        return new StreamReaderProxy($proxy);
    }

    public function loadAddress(): int
    {
        return 0x7C00;
    }

    public function loadSegment(): int
    {
        return 0x0000;
    }

    public function fileSize(): int
    {
        return strlen($this->data);
    }

    public function bootImage(): ?BootImageInterface
    {
        return null;
    }

    public function bootLoadSize(): int
    {
        return min($this->fileSize(), 512);
    }

    public function isNoEmulation(): bool
    {
        return false;
    }

    public function readIsoSectors(int $lba, int $sectorCount): ?string
    {
        return null;
    }

    public function backingFileSize(): int
    {
        return $this->fileSize();
    }

    public function replaceRange(int $offset, string $data): void
    {
    }

    public function char(): string
    {
        if ($this->offset >= strlen($this->data)) {
            return "\0";
        }
        return $this->data[$this->offset++];
    }

    public function byte(): int
    {
        return ord($this->char());
    }

    public function signedByte(): int
    {
        $byte = $this->byte();
        return $byte >= 128 ? $byte - 256 : $byte;
    }

    public function short(): int
    {
        $lo = $this->byte();
        $hi = $this->byte();
        return $lo | ($hi << 8);
    }

    public function signedShort(): int
    {
        $value = $this->short();
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function dword(): int
    {
        $lo = $this->short();
        $hi = $this->short();
        return $lo | ($hi << 16);
    }

    public function signedDword(): int
    {
        $value = $this->dword();
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $newOffset): StreamReaderInterface
    {
        $this->offset = $newOffset;
        return $this;
    }

    public function isEOF(): bool
    {
        return $this->offset >= strlen($this->data);
    }

    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $result = substr($this->data, $this->offset, $length);
        $this->offset += strlen($result);
        return $result;
    }
}
