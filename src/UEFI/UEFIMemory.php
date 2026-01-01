<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

use PHPMachineEmulator\Runtime\RuntimeInterface;

final class UEFIMemory
{
    public function __construct(private RuntimeInterface $runtime)
    {
    }

    public function readU8(int $address): int
    {
        return $this->runtime->memoryAccessor()->readPhysical8($address) & 0xFF;
    }

    public function readU16(int $address): int
    {
        return $this->runtime->memoryAccessor()->readPhysical16($address) & 0xFFFF;
    }

    public function readU32(int $address): int
    {
        return $this->runtime->memoryAccessor()->readPhysical32($address) & 0xFFFFFFFF;
    }

    public function readU64(int $address): int
    {
        return $this->runtime->memoryAccessor()->readPhysical64($address);
    }

    public function writeU8(int $address, int $value): void
    {
        $this->runtime->memoryAccessor()->writeBySize($address, $value & 0xFF, 8);
    }

    public function writeU16(int $address, int $value): void
    {
        $this->runtime->memoryAccessor()->writeBySize($address, $value & 0xFFFF, 16);
    }

    public function writeU32(int $address, int $value): void
    {
        $this->runtime->memoryAccessor()->writeBySize($address, $value & 0xFFFFFFFF, 32);
    }

    public function writeU64(int $address, int $value): void
    {
        $this->runtime->memoryAccessor()->writeBySize($address, $value, 64);
    }

    public function readBytes(int $address, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $data = '';
        for ($i = 0; $i < $length; $i++) {
            $data .= chr($this->readU8($address + $i));
        }
        return $data;
    }

    public function writeBytes(int $address, string $data): void
    {
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $this->writeU8($address + $i, ord($data[$i]));
        }
    }

    public function readUtf16String(int $address, int $maxBytes = 4096): string
    {
        $out = '';
        for ($i = 0; $i + 1 < $maxBytes; $i += 2) {
            $code = $this->readU16($address + $i);
            if ($code === 0x0000) {
                break;
            }
            $out .= $code < 0x80 ? chr($code) : '?';
        }
        return $out;
    }

    public function writeUtf16String(int $address, string $value): int
    {
        $len = strlen($value);
        $offset = 0;
        for ($i = 0; $i < $len; $i++) {
            $this->writeU16($address + $offset, ord($value[$i]));
            $offset += 2;
        }
        $this->writeU16($address + $offset, 0x0000);
        return $offset + 2;
    }

    public function readGuid(int $address): string
    {
        $d1 = $this->readU32($address);
        $d2 = $this->readU16($address + 4);
        $d3 = $this->readU16($address + 6);
        $b0 = $this->readU8($address + 8);
        $b1 = $this->readU8($address + 9);
        $b2 = $this->readU8($address + 10);
        $b3 = $this->readU8($address + 11);
        $b4 = $this->readU8($address + 12);
        $b5 = $this->readU8($address + 13);
        $b6 = $this->readU8($address + 14);
        $b7 = $this->readU8($address + 15);

        return sprintf(
            '%08x-%04x-%04x-%02x%02x-%02x%02x%02x%02x%02x%02x',
            $d1,
            $d2,
            $d3,
            $b0,
            $b1,
            $b2,
            $b3,
            $b4,
            $b5,
            $b6,
            $b7,
        );
    }
}
