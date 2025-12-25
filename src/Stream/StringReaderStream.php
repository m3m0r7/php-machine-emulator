<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

final class StringReaderStream implements StreamReaderInterface
{
    private int $pos = 0;

    public function __construct(private readonly string $data = '')
    {
    }

    public function offset(): int
    {
        return $this->pos;
    }

    public function setOffset(int $newOffset): self
    {
        $this->pos = max(0, $newOffset);
        return $this;
    }

    public function isEOF(): bool
    {
        return $this->pos >= strlen($this->data);
    }

    public function char(): string
    {
        if ($this->isEOF()) {
            return "\0";
        }
        return $this->data[$this->pos++];
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

    public function short(): int
    {
        return $this->byte() | ($this->byte() << 8);
    }

    public function signedShort(): int
    {
        $value = $this->short();
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function dword(): int
    {
        return $this->byte()
            | ($this->byte() << 8)
            | ($this->byte() << 16)
            | ($this->byte() << 24);
    }

    public function signedDword(): int
    {
        $value = $this->dword();
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function read(int $length): string
    {
        if ($length <= 0 || $this->isEOF()) {
            return '';
        }
        $chunk = substr($this->data, $this->pos, $length);
        $this->pos += strlen($chunk);
        return $chunk;
    }
}

