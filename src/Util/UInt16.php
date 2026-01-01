<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Util;

/**
 * Unsigned 16-bit integer utility for x86 emulation.
 *
 * Uses native PHP int with proper masking for unsigned behavior.
 */
class UInt16 implements UnsignedIntegerInterface
{
    private const MASK_16 = 0xFFFF;
    private const SIGN_BIT = 0x8000;

    private int $value;

    private function __construct(int $value)
    {
        $this->value = $value & self::MASK_16;
    }

    private function otherValue(UnsignedIntegerInterface|int $other): int
    {
        if ($other instanceof self) {
            return $other->value;
        }
        if ($other instanceof UnsignedIntegerInterface) {
            return $other->toInt();
        }
        return $other;
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
            $bytes = str_pad($bytes, 2, "\x00", STR_PAD_RIGHT);
            return new self(unpack('v', $bytes)[1]);
        }
        $bytes = str_pad($bytes, 2, "\x00", STR_PAD_LEFT);
        return new self(unpack('n', $bytes)[1]);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function toSignedInt(): int
    {
        if ($this->value & self::SIGN_BIT) {
            return $this->value - (self::MASK_16 + 1);
        }
        return $this->value;
    }

    public function toHex(): string
    {
        return '0x' . str_pad(dechex($this->value), 4, '0', STR_PAD_LEFT);
    }

    public function toBytes(bool $littleEndian = true): string
    {
        if ($littleEndian) {
            return pack('v', $this->value);
        }
        return pack('n', $this->value);
    }

    // Arithmetic operations

    public function add(UnsignedIntegerInterface|int $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return new self($this->value + $otherVal);
    }

    public function sub(UnsignedIntegerInterface|int $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return new self($this->value - $otherVal);
    }

    public function mul(UnsignedIntegerInterface|int $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return new self($this->value * $otherVal);
    }

    /**
     * Multiply and return full 32-bit result as UInt32.
     */
    public function mulFull(UnsignedIntegerInterface|int $other): UInt32
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return UInt32::of($this->value * $otherVal);
    }

    public function div(UnsignedIntegerInterface|int $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return new self(intdiv($this->value, $otherVal));
    }

    public function mod(UnsignedIntegerInterface|int $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return new self($this->value % $otherVal);
    }

    // Bitwise operations

    public function and(UnsignedIntegerInterface|int $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return new self($this->value & $otherVal);
    }

    public function or(UnsignedIntegerInterface|int $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return new self($this->value | $otherVal);
    }

    public function xor(UnsignedIntegerInterface|int $other): self
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
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
            $mask = self::MASK_16 << (16 - $bits);
            return new self(($this->value >> $bits) | $mask);
        }
        return new self($this->value >> $bits);
    }

    public function rol(int $bits): self
    {
        $bits &= 15;
        return new self(($this->value << $bits) | ($this->value >> (16 - $bits)));
    }

    public function ror(int $bits): self
    {
        $bits &= 15;
        return new self(($this->value >> $bits) | ($this->value << (16 - $bits)));
    }

    // Comparison

    public function eq(UnsignedIntegerInterface|int $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return $this->value === $otherVal;
    }

    public function lt(UnsignedIntegerInterface|int $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return $this->value < $otherVal;
    }

    public function lte(UnsignedIntegerInterface|int $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return $this->value <= $otherVal;
    }

    public function gt(UnsignedIntegerInterface|int $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
        return $this->value > $otherVal;
    }

    public function gte(UnsignedIntegerInterface|int $other): bool
    {
        $otherVal = $this->otherValue($other) & self::MASK_16;
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
     * Get lower 8 bits.
     */
    public function low8(): int
    {
        return $this->value & 0xFF;
    }

    /**
     * Get upper 8 bits.
     */
    public function high8(): int
    {
        return ($this->value >> 8) & 0xFF;
    }

    /**
     * Create from low and high 8-bit parts.
     */
    public static function fromParts(int $low, int $high): self
    {
        return new self((($high & 0xFF) << 8) | ($low & 0xFF));
    }

    /**
     * Convert to UInt32.
     */
    public function toUInt32(): UInt32
    {
        return UInt32::of($this->value);
    }

    /**
     * Convert to UInt64.
     */
    public function toUInt64(): UInt64
    {
        return UInt64::of($this->value);
    }
}
