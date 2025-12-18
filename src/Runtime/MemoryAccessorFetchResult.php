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
                64 => $this->value,
                default => $size >= 63 ? $this->value : ($this->value & ((1 << $size) - 1)),
            };
        }

        // Register values are stored in native format, just mask to requested size
        return match ($size) {
            8 => $this->value & 0xFF,
            16 => $this->value & 0xFFFF,
            32 => $this->value & 0xFFFFFFFF,
            64 => $this->value,
            default => $size >= 63 ? $this->value : ($this->value & ((1 << $size) - 1)),
        };
    }

    public function asLowBit(): int
    {
        // Get low byte (bits 0-7) directly - values are stored in native format
        return ($this->value ?? 0) & 0xFF;
    }

    public function asHighBit(): int
    {
        // Get high byte (bits 8-15) directly - values are stored in native format
        return (($this->value ?? 0) >> 8) & 0xFF;
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
