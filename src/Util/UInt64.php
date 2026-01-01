<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Util;

use FFI;
use PHPMachineEmulator\Stream\RustFFIContext;

/**
 * Unsigned 64-bit integer utility for x86-64 emulation.
 *
 * Backed by Rust FFI for arithmetic/bitwise operations to avoid BigInteger.
 */
class UInt64 implements UnsignedIntegerInterface
{
    private const MASK_32 = 0xFFFFFFFF;
    private const DECIMAL_BUFFER_LEN = 21; // 20 digits + NUL

    private int $low;
    private int $high;

    private static ?RustFFIContext $ffiContext = null;

    private function __construct(int $low, int $high)
    {
        $this->low = $low & self::MASK_32;
        $this->high = $high & self::MASK_32;
    }

    private static function ffiContext(): RustFFIContext
    {
        return self::$ffiContext ??= new RustFFIContext();
    }

    private static function fromInt(int $value): self
    {
        $low = $value & self::MASK_32;
        $high = ($value >> 32) & self::MASK_32;
        return new self($low, $high);
    }

    private static function fromDecimal(string $value): self
    {
        $ffi = self::ffiContext();
        $low = $ffi->new('uint32_t');
        $high = $ffi->new('uint32_t');
        $ok = $ffi->uint64_from_decimal($value, FFI::addr($low), FFI::addr($high));
        if (!$ok) {
            throw new \InvalidArgumentException('Invalid UInt64 decimal string');
        }
        return new self((int) $low->cdata, (int) $high->cdata);
    }

    private static function normalize(UnsignedIntegerInterface|int|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_int($value)) {
            return self::fromInt($value);
        }
        return self::fromDecimal($value);
    }

    public static function of(int|string|self $value): self
    {
        return self::normalize($value);
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    public static function fromBytes(string $bytes, bool $littleEndian = true): self
    {
        if ($littleEndian) {
            $bytes = str_pad($bytes, 8, "\x00", STR_PAD_RIGHT);
            $parts = unpack('V2', $bytes);
            return new self($parts[1], $parts[2]);
        }

        $bytes = str_pad($bytes, 8, "\x00", STR_PAD_LEFT);
        $parts = unpack('N2', $bytes);
        return new self($parts[2], $parts[1]);
    }

    /**
     * Returns native int (signed 64-bit two's complement).
     */
    public function toNative(): int
    {
        return $this->toInt();
    }

    /**
     * Force to int (two's complement signed 64-bit).
     */
    public function toInt(): int
    {
        return self::ffiContext()->uint64_to_i64($this->low, $this->high);
    }

    /**
     * Returns unsigned value as string.
     */
    public function toString(): string
    {
        $ffi = self::ffiContext();
        $buffer = $ffi->new('char[' . self::DECIMAL_BUFFER_LEN . ']');
        $ok = $ffi->uint64_to_decimal($this->low, $this->high, $buffer, self::DECIMAL_BUFFER_LEN);
        if (!$ok) {
            return '0';
        }
        return FFI::string($buffer);
    }

    public function toHex(): string
    {
        return sprintf('0x%08x%08x', $this->high, $this->low);
    }

    public function toBytes(int $length = 8, bool $littleEndian = true): string
    {
        $bytes = $littleEndian
            ? pack('V2', $this->low, $this->high)
            : pack('N2', $this->high, $this->low);
        return substr($bytes, 0, $length);
    }

    private static function applyBinary(string $fn, self $left, self $right): self
    {
        $ffi = self::ffiContext();
        $low = $ffi->new('uint32_t');
        $high = $ffi->new('uint32_t');
        $ffi->$fn(
            $left->low,
            $left->high,
            $right->low,
            $right->high,
            FFI::addr($low),
            FFI::addr($high),
        );
        return new self((int) $low->cdata, (int) $high->cdata);
    }

    private static function applyUnary(string $fn, self $value, int $bits = 0): self
    {
        $ffi = self::ffiContext();
        $low = $ffi->new('uint32_t');
        $high = $ffi->new('uint32_t');

        if ($fn === 'uint64_shl' || $fn === 'uint64_shr') {
            $ffi->$fn(
                $value->low,
                $value->high,
                $bits,
                FFI::addr($low),
                FFI::addr($high),
            );
        } else {
            $ffi->$fn(
                $value->low,
                $value->high,
                FFI::addr($low),
                FFI::addr($high),
            );
        }

        return new self((int) $low->cdata, (int) $high->cdata);
    }

    // Arithmetic operations

    public function add(UnsignedIntegerInterface|int|string $other): self
    {
        return self::applyBinary('uint64_add', $this, self::normalize($other));
    }

    public function sub(UnsignedIntegerInterface|int|string $other): self
    {
        return self::applyBinary('uint64_sub', $this, self::normalize($other));
    }

    public function mul(UnsignedIntegerInterface|int|string $other): self
    {
        return self::applyBinary('uint64_mul', $this, self::normalize($other));
    }

    public function div(UnsignedIntegerInterface|int|string $other): self
    {
        $rhs = self::normalize($other);
        if ($rhs->isZero()) {
            throw new \DivisionByZeroError('Division by zero');
        }

        $ffi = self::ffiContext();
        $low = $ffi->new('uint32_t');
        $high = $ffi->new('uint32_t');
        $ok = $ffi->uint64_div(
            $this->low,
            $this->high,
            $rhs->low,
            $rhs->high,
            FFI::addr($low),
            FFI::addr($high),
        );
        if (!$ok) {
            throw new \DivisionByZeroError('Division by zero');
        }

        return new self((int) $low->cdata, (int) $high->cdata);
    }

    public function mod(UnsignedIntegerInterface|int|string $other): self
    {
        $rhs = self::normalize($other);
        if ($rhs->isZero()) {
            throw new \DivisionByZeroError('Division by zero');
        }

        $ffi = self::ffiContext();
        $low = $ffi->new('uint32_t');
        $high = $ffi->new('uint32_t');
        $ok = $ffi->uint64_mod(
            $this->low,
            $this->high,
            $rhs->low,
            $rhs->high,
            FFI::addr($low),
            FFI::addr($high),
        );
        if (!$ok) {
            throw new \DivisionByZeroError('Division by zero');
        }

        return new self((int) $low->cdata, (int) $high->cdata);
    }

    // Bitwise operations

    public function and(UnsignedIntegerInterface|int|string $other): self
    {
        return self::applyBinary('uint64_and', $this, self::normalize($other));
    }

    public function or(UnsignedIntegerInterface|int|string $other): self
    {
        return self::applyBinary('uint64_or', $this, self::normalize($other));
    }

    public function xor(UnsignedIntegerInterface|int|string $other): self
    {
        return self::applyBinary('uint64_xor', $this, self::normalize($other));
    }

    public function not(): self
    {
        return self::applyUnary('uint64_not', $this);
    }

    public function shl(int $bits): self
    {
        if ($bits <= 0) {
            return $this;
        }
        if ($bits >= 64) {
            return self::zero();
        }
        return self::applyUnary('uint64_shl', $this, $bits);
    }

    public function shr(int $bits): self
    {
        if ($bits <= 0) {
            return $this;
        }
        if ($bits >= 64) {
            return self::zero();
        }
        return self::applyUnary('uint64_shr', $this, $bits);
    }

    // Comparison

    public function eq(UnsignedIntegerInterface|int|string $other): bool
    {
        $rhs = self::normalize($other);
        return self::ffiContext()->uint64_eq($this->low, $this->high, $rhs->low, $rhs->high);
    }

    public function lt(UnsignedIntegerInterface|int|string $other): bool
    {
        $rhs = self::normalize($other);
        return self::ffiContext()->uint64_lt($this->low, $this->high, $rhs->low, $rhs->high);
    }

    public function lte(UnsignedIntegerInterface|int|string $other): bool
    {
        $rhs = self::normalize($other);
        return self::ffiContext()->uint64_lte($this->low, $this->high, $rhs->low, $rhs->high);
    }

    public function gt(UnsignedIntegerInterface|int|string $other): bool
    {
        $rhs = self::normalize($other);
        return self::ffiContext()->uint64_gt($this->low, $this->high, $rhs->low, $rhs->high);
    }

    public function gte(UnsignedIntegerInterface|int|string $other): bool
    {
        $rhs = self::normalize($other);
        return self::ffiContext()->uint64_gte($this->low, $this->high, $rhs->low, $rhs->high);
    }

    public function isZero(): bool
    {
        return self::ffiContext()->uint64_is_zero($this->low, $this->high);
    }

    /**
     * Check if high bit (bit 63) is set.
     */
    public function isNegativeSigned(): bool
    {
        return self::ffiContext()->uint64_is_negative_signed($this->low, $this->high);
    }

    /**
     * Get lower 32 bits as native int.
     */
    public function low32(): int
    {
        return $this->low;
    }

    /**
     * Get upper 32 bits as native int.
     */
    public function high32(): int
    {
        return $this->high;
    }

    /**
     * Create from low and high 32-bit parts.
     */
    public static function fromParts(int $low, int $high): self
    {
        return new self($low, $high);
    }

    /**
     * Multiply and return full 128-bit product (low/high 64-bit parts).
     *
     * @return array{0: UInt64, 1: UInt64}
     */
    public function mulFull(UnsignedIntegerInterface|int|string $other): array
    {
        $rhs = self::normalize($other);
        $ffi = self::ffiContext();
        $lowLow = $ffi->new('uint32_t');
        $lowHigh = $ffi->new('uint32_t');
        $highLow = $ffi->new('uint32_t');
        $highHigh = $ffi->new('uint32_t');

        $ffi->uint64_mul_full(
            $this->low,
            $this->high,
            $rhs->low,
            $rhs->high,
            FFI::addr($lowLow),
            FFI::addr($lowHigh),
            FFI::addr($highLow),
            FFI::addr($highHigh),
        );

        return [
            new self((int) $lowLow->cdata, (int) $lowHigh->cdata),
            new self((int) $highLow->cdata, (int) $highHigh->cdata),
        ];
    }

    /**
     * Signed multiply and return full 128-bit product (low/high 64-bit parts).
     *
     * @return array{0: UInt64, 1: UInt64}
     */
    public function mulFullSigned(UnsignedIntegerInterface|int|string $other): array
    {
        $rhs = self::normalize($other);
        $ffi = self::ffiContext();
        $lowLow = $ffi->new('uint32_t');
        $lowHigh = $ffi->new('uint32_t');
        $highLow = $ffi->new('uint32_t');
        $highHigh = $ffi->new('uint32_t');

        $ffi->uint64_mul_full_signed(
            $this->low,
            $this->high,
            $rhs->low,
            $rhs->high,
            FFI::addr($lowLow),
            FFI::addr($lowHigh),
            FFI::addr($highLow),
            FFI::addr($highHigh),
        );

        return [
            new self((int) $lowLow->cdata, (int) $lowHigh->cdata),
            new self((int) $highLow->cdata, (int) $highHigh->cdata),
        ];
    }

    /**
     * Unsigned divide 128-bit (high:low) by 64-bit divisor.
     *
     * @return array{0: UInt64, 1: UInt64}
     */
    public static function divMod128(self $high, self $low, UnsignedIntegerInterface|int|string $divisor): array
    {
        $div = self::normalize($divisor);
        if ($div->isZero()) {
            throw new \DivisionByZeroError('Division by zero');
        }

        $ffi = self::ffiContext();
        $qLow = $ffi->new('uint32_t');
        $qHigh = $ffi->new('uint32_t');
        $rLow = $ffi->new('uint32_t');
        $rHigh = $ffi->new('uint32_t');

        $ok = $ffi->uint128_divmod_u64(
            $low->low,
            $low->high,
            $high->low,
            $high->high,
            $div->low,
            $div->high,
            FFI::addr($qLow),
            FFI::addr($qHigh),
            FFI::addr($rLow),
            FFI::addr($rHigh),
        );
        if (!$ok) {
            throw new \OverflowException('Divide overflow');
        }

        return [
            new self((int) $qLow->cdata, (int) $qHigh->cdata),
            new self((int) $rLow->cdata, (int) $rHigh->cdata),
        ];
    }

    /**
     * Signed divide 128-bit (high:low) by signed 64-bit divisor.
     *
     * @return array{0: int, 1: int}
     */
    public static function divModSigned128(self $high, self $low, int $divisor): array
    {
        if ($divisor === 0) {
            throw new \DivisionByZeroError('Division by zero');
        }

        $ffi = self::ffiContext();
        $q = $ffi->new('int64_t');
        $r = $ffi->new('int64_t');

        $ok = $ffi->int128_divmod_i64(
            $low->low,
            $low->high,
            $high->low,
            $high->high,
            $divisor,
            FFI::addr($q),
            FFI::addr($r),
        );
        if (!$ok) {
            throw new \OverflowException('Divide overflow');
        }

        return [(int) $q->cdata, (int) $r->cdata];
    }
}
