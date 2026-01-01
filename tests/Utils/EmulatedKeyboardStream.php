<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Stream\StreamReaderInterface;

/**
 * Emulated keyboard stream for testing purposes.
 * Does not extend KeyboardReaderStream to avoid resource requirement.
 */
class EmulatedKeyboardStream implements StreamReaderInterface
{
    private int $pos = 0;

    public function __construct(private readonly string $input = "Hello World!\r")
    {
    }

    public function char(): string
    {
        if ($this->pos >= strlen($this->input)) {
            return "\0"; // Return null character when input exhausted
        }
        return $this->input[$this->pos++];
    }

    public function byte(): int
    {
        $char = $this->char();
        return ord($char);
    }

    public function offset(): int
    {
        return $this->pos;
    }

    public function setOffset(int $newOffset): StreamReaderInterface
    {
        $this->pos = $newOffset;
        return $this;
    }

    public function isEOF(): bool
    {
        return $this->pos >= strlen($this->input);
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
        return $this->byte() | ($this->byte() << 8) | ($this->byte() << 16) | ($this->byte() << 24);
    }

    public function signedDword(): int
    {
        $value = $this->dword();
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $result = substr($this->input, $this->pos, $length);
        $this->pos += strlen($result);
        return $result;
    }
}
