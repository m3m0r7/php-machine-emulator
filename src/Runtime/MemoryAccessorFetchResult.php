<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Util\BinaryInteger;

class MemoryAccessorFetchResult implements MemoryAccessorFetchResultInterface
{
    /** @var array<string, MemoryAccessorFetchResult> */
    private static array $cache = [];

    public function __construct(
        protected int|null $value,
        protected int $storedSizeValue = 16,
        protected bool $alreadyDecoded = false,
    ) {
    }

    /**
     * Get a cached instance for the given value and storedSize.
     */
    public static function fromCache(int|null $value, int $storedSize): self
    {
        // For null or alreadyDecoded cases, don't cache
        if ($value === null) {
            return new self($value, $storedSize);
        }

        $key = $value . '_' . $storedSize;
        return self::$cache[$key] ??= new self($value, $storedSize);
    }

    public function asChar(): string
    {
        if ($this->value === null) {
            return chr(0);
        }
        return chr($this->value);
    }

    public function asLowBitChar(): string
    {
        if ($this->value === null) {
            return chr(0);
        }
        return chr($this->asLowBit());
    }

    public function asHighBitChar(): string
    {
        if ($this->value === null) {
            return chr(0);
        }
        return chr($this->asHighBit());
    }

    public function asByte(): int
    {
        return $this->asBytesBySize(16);
    }

    public function asBytesBySize(int $size = 64): int
    {
        if ($this->value === null) {
            return 0;
        }

        // If the value is already decoded (e.g., from stack operations), skip byte swap
        if ($this->alreadyDecoded) {
            return match ($size) {
                8 => $this->value & 0xFF,
                16 => $this->value & 0xFFFF,
                32 => $this->value & 0xFFFFFFFF,
                default => $this->value & ((1 << $size) - 1),
            };
        }

        // For GPRs (storedSize=32), decode from 32-bit format then mask
        // For non-GPRs (storedSize=16), use the requested size for decoding (legacy behavior)
        $decodeSize = $this->storedSizeValue === 32 ? 32 : $size;
        $decoded = BinaryInteger::asLittleEndian($this->value, $decodeSize);

        return match ($size) {
            8 => $decoded & 0xFF,
            16 => $decoded & 0xFFFF,
            32 => $decoded & 0xFFFFFFFF,
            default => $decoded & ((1 << $size) - 1),
        };
    }

    public function asLowBit(): int
    {
        // Decode first, then get low byte
        $decoded = BinaryInteger::asLittleEndian($this->value ?? 0, $this->storedSizeValue);
        return $decoded & 0xFF;
    }

    public function asHighBit(): int
    {
        // Decode first, then get high byte (bits 8-15)
        $decoded = BinaryInteger::asLittleEndian($this->value ?? 0, $this->storedSizeValue);
        return ($decoded >> 8) & 0xFF;
    }

    public function valueOf(): int|null
    {
        return $this->value;
    }

    public function storedSize(): int
    {
        return $this->storedSizeValue;
    }
}
