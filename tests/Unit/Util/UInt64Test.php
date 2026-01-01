<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use PHPMachineEmulator\Util\UInt64;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UInt64Test extends TestCase
{
    #[Test]
    public function createFromInt(): void
    {
        $value = UInt64::of(0x12345678);
        $this->assertSame(0x12345678, $value->toNative());
    }

    #[Test]
    public function createFromString(): void
    {
        // Value larger than PHP_INT_MAX
        $value = UInt64::of('18446744073709551615'); // 0xFFFFFFFFFFFFFFFF
        $this->assertSame('18446744073709551615', $value->toString());
    }

    #[Test]
    public function addWithinNativeRange(): void
    {
        $a = UInt64::of(100);
        $b = UInt64::of(200);
        $result = $a->add($b);
        $this->assertSame(300, $result->toNative());
    }

    #[Test]
    public function addOverflow(): void
    {
        // 0xFFFFFFFFFFFFFFFF + 1 should wrap to 0
        $a = UInt64::of('18446744073709551615');
        $result = $a->add(1);
        $this->assertTrue($result->isZero());
    }

    #[Test]
    public function addOverflowWraps(): void
    {
        // 0xFFFFFFFFFFFFFFFF + 2 should wrap to 1
        $a = UInt64::of('18446744073709551615');
        $result = $a->add(2);
        $this->assertSame(1, $result->toNative());
    }

    #[Test]
    public function subWithinNativeRange(): void
    {
        $a = UInt64::of(300);
        $result = $a->sub(100);
        $this->assertSame(200, $result->toNative());
    }

    #[Test]
    public function subUnderflow(): void
    {
        // 0 - 1 should wrap to 0xFFFFFFFFFFFFFFFF
        $a = UInt64::zero();
        $result = $a->sub(1);
        $this->assertSame('18446744073709551615', $result->toString());
    }

    #[Test]
    public function mulWithinNativeRange(): void
    {
        $a = UInt64::of(1000);
        $result = $a->mul(1000);
        $this->assertSame(1000000, $result->toNative());
    }

    #[Test]
    public function mulLargeValues(): void
    {
        // 0x100000000 * 0x100000000 = 0x10000000000000000, masked to 0
        $a = UInt64::of(0x100000000);
        $result = $a->mul(0x100000000);
        $this->assertTrue($result->isZero());
    }

    #[Test]
    public function divBasic(): void
    {
        $a = UInt64::of(1000);
        $result = $a->div(10);
        $this->assertSame(100, $result->toNative());
    }

    #[Test]
    public function modBasic(): void
    {
        $a = UInt64::of(1003);
        $result = $a->mod(10);
        $this->assertSame(3, $result->toNative());
    }

    #[Test]
    public function bitwiseAnd(): void
    {
        $a = UInt64::of(0xFF00FF00);
        $result = $a->and(0x00FF00FF);
        $this->assertSame(0, $result->toNative());
    }

    #[Test]
    public function bitwiseOr(): void
    {
        $a = UInt64::of(0xFF00FF00);
        $result = $a->or(0x00FF00FF);
        $this->assertSame(0xFFFFFFFF, $result->toNative());
    }

    #[Test]
    public function bitwiseXor(): void
    {
        $a = UInt64::of(0xAAAAAAAA);
        $result = $a->xor(0x55555555);
        $this->assertSame(0xFFFFFFFF, $result->toNative());
    }

    #[Test]
    public function bitwiseNot(): void
    {
        $a = UInt64::of(0);
        $result = $a->not();
        $this->assertSame('18446744073709551615', $result->toString());
    }

    #[Test]
    public function shiftLeft(): void
    {
        $a = UInt64::of(1);
        $result = $a->shl(32);
        $this->assertSame(0x100000000, $result->toNative());
    }

    #[Test]
    public function shiftLeftOverflow(): void
    {
        $a = UInt64::of(1);
        $result = $a->shl(64);
        $this->assertTrue($result->isZero());
    }

    #[Test]
    public function shiftRight(): void
    {
        $a = UInt64::of(0x100000000);
        $result = $a->shr(32);
        $this->assertSame(1, $result->toNative());
    }

    #[Test]
    public function low32(): void
    {
        $a = UInt64::of(0x123456789ABCDEF0);
        $this->assertSame(0x9ABCDEF0, $a->low32());
    }

    #[Test]
    public function high32(): void
    {
        $a = UInt64::of(0x123456789ABCDEF0);
        $this->assertSame(0x12345678, $a->high32());
    }

    #[Test]
    public function fromParts(): void
    {
        $a = UInt64::fromParts(0xDEADBEEF, 0x12345678);
        $this->assertSame(0x12345678DEADBEEF, $a->toNative());
    }

    #[Test]
    public function toBytes(): void
    {
        $a = UInt64::of(0x0102030405060708);
        $bytes = $a->toBytes(8, true);
        $this->assertSame("\x08\x07\x06\x05\x04\x03\x02\x01", $bytes);
    }

    #[Test]
    public function fromBytes(): void
    {
        $bytes = "\x08\x07\x06\x05\x04\x03\x02\x01";
        $a = UInt64::fromBytes($bytes, true);
        $this->assertSame(0x0102030405060708, $a->toNative());
    }

    #[Test]
    public function toHex(): void
    {
        $a = UInt64::of(0xDEADBEEF);
        $this->assertSame('0x00000000deadbeef', $a->toHex());
    }

    #[Test]
    public function isNegativeSigned(): void
    {
        // Bit 63 set
        $a = UInt64::of('9223372036854775808'); // 0x8000000000000000
        $this->assertTrue($a->isNegativeSigned());

        // Bit 63 not set
        $b = UInt64::of('9223372036854775807'); // 0x7FFFFFFFFFFFFFFF
        $this->assertFalse($b->isNegativeSigned());
    }

    #[Test]
    public function comparison(): void
    {
        $a = UInt64::of(100);
        $b = UInt64::of(200);

        $this->assertTrue($a->lt($b));
        $this->assertTrue($a->lte($b));
        $this->assertFalse($a->gt($b));
        $this->assertFalse($a->gte($b));
        $this->assertFalse($a->eq($b));

        $this->assertTrue($a->eq(100));
    }

    #[Test]
    public function mulFullReturnsHighAndLow(): void
    {
        $a = UInt64::of('18446744073709551615'); // 0xFFFFFFFFFFFFFFFF
        $b = UInt64::of(2);
        [$low, $high] = $a->mulFull($b);

        $this->assertSame('18446744073709551614', $low->toString()); // 0xFFFFFFFFFFFFFFFE
        $this->assertSame(1, $high->toNative());
    }

    #[Test]
    public function mulFullSignedHandlesNegativeOperands(): void
    {
        $a = UInt64::of(-2);
        $b = UInt64::of(3);
        [$low, $high] = $a->mulFullSigned($b);

        $this->assertSame('18446744073709551610', $low->toString()); // 0xFFFFFFFFFFFFFFFA
        $this->assertSame('18446744073709551615', $high->toString()); // 0xFFFFFFFFFFFFFFFF
    }

    #[Test]
    public function divMod128DividesUnsigned128(): void
    {
        $high = UInt64::of(1);
        $low = UInt64::of(0);
        [$quotient, $remainder] = UInt64::divMod128($high, $low, 2);

        $this->assertSame('9223372036854775808', $quotient->toString());
        $this->assertTrue($remainder->isZero());
    }

    #[Test]
    public function divModSigned128DividesSigned128(): void
    {
        $high = UInt64::of(0);
        $low = UInt64::of(6);
        [$quotient, $remainder] = UInt64::divModSigned128($high, $low, 2);

        $this->assertSame(3, $quotient);
        $this->assertSame(0, $remainder);
    }
}
