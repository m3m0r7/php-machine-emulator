<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Util;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Unsigned 64-bit integer utility for x86-64 emulation.
 *
 * Uses native PHP int for values that fit in signed 63-bit range,
 * falls back to BigInteger for larger values.
 */
class UInt64
{
    private const MAX_NATIVE = 0x7FFFFFFFFFFFFFFF; // PHP_INT_MAX on 64-bit
    private const MASK_64 = '18446744073709551615'; // 0xFFFFFFFFFFFFFFFF as string

    private BigInteger $value;

    private function __construct(BigInteger $value)
    {
        $this->value = $value->and(BigInteger::of(self::MASK_64));
    }

    public static function of(int|string|BigInteger $value): self
    {
        if ($value instanceof BigInteger) {
            return new self($value);
        }
        return new self(BigInteger::of($value));
    }

    public static function zero(): self
    {
        return new self(BigInteger::zero());
    }

    public static function fromBytes(string $bytes, bool $littleEndian = true): self
    {
        if ($littleEndian) {
            $bytes = strrev($bytes);
        }
        $hex = bin2hex($bytes);
        return new self(BigInteger::fromBase($hex, 16));
    }

    /**
     * Returns native int if value fits, otherwise BigInteger.
     */
    public function toNative(): int|BigInteger
    {
        if ($this->value->isLessThanOrEqualTo(self::MAX_NATIVE)) {
            return $this->value->toInt();
        }
        return $this->value;
    }

    /**
     * Always returns BigInteger.
     */
    public function toBigInteger(): BigInteger
    {
        return $this->value;
    }

    /**
     * Force to int (may overflow/wrap for large values).
     */
    public function toInt(): int
    {
        // For values > PHP_INT_MAX, this will wrap to negative
        $hex = $this->value->toBase(16);
        $hex = str_pad($hex, 16, '0', STR_PAD_LEFT);
        $packed = hex2bin($hex);
        return unpack('q', strrev($packed))[1]; // Little-endian signed 64-bit
    }

    /**
     * Returns unsigned value as string.
     */
    public function toString(): string
    {
        return (string) $this->value;
    }

    public function toHex(): string
    {
        return '0x' . str_pad($this->value->toBase(16), 16, '0', STR_PAD_LEFT);
    }

    public function toBytes(int $length = 8, bool $littleEndian = true): string
    {
        $hex = str_pad($this->value->toBase(16), $length * 2, '0', STR_PAD_LEFT);
        $bytes = hex2bin($hex);
        if ($littleEndian) {
            $bytes = strrev($bytes);
        }
        return substr($bytes, 0, $length);
    }

    // Arithmetic operations

    public function add(self|int|string $other): self
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return new self($this->value->plus($otherBig));
    }

    public function sub(self|int|string $other): self
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        $result = $this->value->minus($otherBig);
        // Handle underflow (wrap around)
        if ($result->isNegative()) {
            $result = $result->plus(BigInteger::of(self::MASK_64)->plus(1));
        }
        return new self($result);
    }

    public function mul(self|int|string $other): self
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return new self($this->value->multipliedBy($otherBig));
    }

    public function div(self|int|string $other): self
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return new self($this->value->dividedBy($otherBig, RoundingMode::DOWN));
    }

    public function mod(self|int|string $other): self
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return new self($this->value->mod($otherBig));
    }

    // Bitwise operations

    public function and(self|int|string $other): self
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return new self($this->value->and($otherBig));
    }

    public function or(self|int|string $other): self
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return new self($this->value->or($otherBig));
    }

    public function xor(self|int|string $other): self
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return new self($this->value->xor($otherBig));
    }

    public function not(): self
    {
        // XOR with all 1s for 64-bit NOT
        return $this->xor(self::MASK_64);
    }

    public function shl(int $bits): self
    {
        return new self($this->value->shiftedLeft($bits));
    }

    public function shr(int $bits): self
    {
        return new self($this->value->shiftedRight($bits));
    }

    // Comparison

    public function eq(self|int|string $other): bool
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return $this->value->isEqualTo($otherBig);
    }

    public function lt(self|int|string $other): bool
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return $this->value->isLessThan($otherBig);
    }

    public function lte(self|int|string $other): bool
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return $this->value->isLessThanOrEqualTo($otherBig);
    }

    public function gt(self|int|string $other): bool
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return $this->value->isGreaterThan($otherBig);
    }

    public function gte(self|int|string $other): bool
    {
        $otherBig = $other instanceof self ? $other->value : BigInteger::of($other);
        return $this->value->isGreaterThanOrEqualTo($otherBig);
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    /**
     * Check if high bit (bit 63) is set.
     */
    public function isNegativeSigned(): bool
    {
        return $this->value->shiftedRight(63)->isEqualTo(1);
    }

    /**
     * Get lower 32 bits as native int.
     */
    public function low32(): int
    {
        return $this->and(0xFFFFFFFF)->value->toInt();
    }

    /**
     * Get upper 32 bits as native int.
     */
    public function high32(): int
    {
        return $this->shr(32)->and(0xFFFFFFFF)->value->toInt();
    }

    /**
     * Create from low and high 32-bit parts.
     */
    public static function fromParts(int $low, int $high): self
    {
        $highBig = BigInteger::of($high & 0xFFFFFFFF)->shiftedLeft(32);
        $lowBig = BigInteger::of($low & 0xFFFFFFFF);
        return new self($highBig->or($lowBig));
    }
}
