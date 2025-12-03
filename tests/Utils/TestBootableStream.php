<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Stream\BootableStreamInterface;
use PHPMachineEmulator\Stream\StreamReaderInterface;

class TestBootableStream implements BootableStreamInterface
{
    private int $offset = 0;
    private string $data;

    public function __construct(string $data = '')
    {
        $this->data = $data ?: str_repeat("\x00", 512);
    }

    public function proxy(): StreamReaderInterface
    {
        return $this;
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

    public function dword(): int
    {
        $lo = $this->short();
        $hi = $this->short();
        return $lo | ($hi << 16);
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
}
