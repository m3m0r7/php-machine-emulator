<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Util;

/**
 * Unsigned 32-bit integer utility for x86 emulation.
 *
 * Uses native PHP int with proper masking for unsigned behavior.
 */
class UInt32 implements UnsignedIntegerInterface
{
    private const MASK_32 = 0xFFFFFFFF;
    private const SIGN_BIT = 0x80000000;

    private int $value;

    private function __construct(int $value)
    {
        $this->value = $value & self::MASK_32;
    }

    private function otherValue(UnsignedIntegerInterface|int|string $other): int
    {
        if ($other instanceof self) {
            return $other->value;
        }
        if ($other instanceof UnsignedIntegerInterface) {
            return $other->toInt();
        }
        return (int) $other;
    }

    public static function of(int $value): self
    {
        return new self($value);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromBytes(string $bytes, bool $littleEndian = true): self
    {
        if ($littleEndian) {
            $bytes = str_pad($bytes, 4, "\x00", STR_PAD_RIGHT);
            return new self(unpack('V', $bytes)[1]);
        }
        $bytes = str_pad($bytes, 4, "\x00", STR_PAD_LEFT);
        return new self(unpack('N', $bytes)[1]);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function toSignedInt(): int
    {
        if ($this->value & self::SIGN_BIT) {
            return $this->value - (self::MASK_32 + 1);
        }
        return $this->value;
    }

    public function toHex(): string
    {
        return '0x' . str_pad(dechex($this->value), 8, '0', STR_PAD_LEFT);
    }

    public function toBytes(bool $littleEndian = true): string
    {
        if ($littleEndian) {
            return pack('V', $this->value);
        }
        return pack('N', $this->value);
    }

    // Arithmetic operations

    public function add(UnsignedIntegerInterface|int|string $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return new self($this->value + $otherVal);
    }

    public function sub(UnsignedIntegerInterface|int|string $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return new self($this->value - $otherVal);
    }

    public function mul(UnsignedIntegerInterface|int|string $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return new self($this->value * $otherVal);
    }

    /**
     * Multiply and return full 64-bit result as UInt64.
     */
    public function mulFull(UnsignedIntegerInterface|int|string $other): UInt64
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return UInt64::of($this->value)->mul($otherVal);
    }

    public function div(UnsignedIntegerInterface|int|string $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return new self(intdiv($this->value, $otherVal));
    }

    public function mod(UnsignedIntegerInterface|int|string $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return new self($this->value % $otherVal);
    }

    // Bitwise operations

    public function and(UnsignedIntegerInterface|int|string $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return new self($this->value & $otherVal);
    }

    public function or(UnsignedIntegerInterface|int|string $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return new self($this->value | $otherVal);
    }

    public function xor(UnsignedIntegerInterface|int|string $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return new self($this->value ^ $otherVal);
    }

    public function not(): self
    {
        return new self(~$this->value);
    }

    public function shl(int $bits): self
    {
        return new self($this->value << $bits);
    }

    public function shr(int $bits): self
    {
        return new self($this->value >> $bits);
    }

    /**
     * Arithmetic right shift (preserves sign bit).
     */
    public function sar(int $bits): self
    {
        if ($this->value & self::SIGN_BIT) {
            // Sign extend
            $mask = self::MASK_32 << (32 - $bits);
            return new self(($this->value >> $bits) | $mask);
        }
        return new self($this->value >> $bits);
    }

    public function rol(int $bits): self
    {
        $bits &= 31;
        return new self(($this->value << $bits) | ($this->value >> (32 - $bits)));
    }

    public function ror(int $bits): self
    {
        $bits &= 31;
        return new self(($this->value >> $bits) | ($this->value << (32 - $bits)));
    }

    // Comparison

    public function eq(UnsignedIntegerInterface|int|string $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return $this->value === $otherVal;
    }

    public function lt(UnsignedIntegerInterface|int|string $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return $this->value < $otherVal;
    }

    public function lte(UnsignedIntegerInterface|int|string $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return $this->value <= $otherVal;
    }

    public function gt(UnsignedIntegerInterface|int|string $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return $this->value > $otherVal;
    }

    public function gte(UnsignedIntegerInterface|int|string $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_32;
        return $this->value >= $otherVal;
    }

    public function isZero(): bool
    {
        return $this->value === 0;
    }

    public function isNegativeSigned(): bool
    {
        return ($this->value & self::SIGN_BIT) !== 0;
    }

    /**
     * Get lower 16 bits.
     */
    public function low16(): int
    {
        return $this->value & 0xFFFF;
    }

    /**
     * Get upper 16 bits.
     */
    public function high16(): int
    {
        return ($this->value >> 16) & 0xFFFF;
    }

    /**
     * Create from low and high 16-bit parts.
     */
    public static function fromParts(int $low, int $high): self
    {
        return new self((($high & 0xFFFF) << 16) | ($low & 0xFFFF));
    }

    /**
     * Convert to UInt64.
     */
    public function toUInt64(): UInt64
    {
        return UInt64::of($this->value);
    }
}
