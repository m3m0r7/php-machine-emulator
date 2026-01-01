<?php

declare(strict_types=1);

namespace Tests\Case\Utils;

use PHPMachineEmulator\Util\BinaryInteger;
use PHPUnit\Framework\TestCase;
use Tests\CreateApplication;

class BinaryIntegerTest extends TestCase
{
    use CreateApplication;

    public function testAsLittleEndian16BitWithZero()
    {
        $value = 0x0;
        $converted = BinaryInteger::asLittleEndian($value, 16);
        $this->assertSame(0x0, $converted);

        $converted = BinaryInteger::asLittleEndian($converted, 16);
        $this->assertSame(0x0, $converted);
    }

    public function testAsLittleEndian16Bit()
    {
        $value = 0xBEEF;
        $converted = BinaryInteger::asLittleEndian($value, 16);
        $this->assertSame(0xEFBE, $converted);

        $converted = BinaryInteger::asLittleEndian($converted, 16);
        $this->assertSame(0xBEEF, $converted);
    }

    public function testAsLittleEndian32Bit()
    {
        $value = 0xC001BEEF;
        $converted = BinaryInteger::asLittleEndian($value, 32);
        $this->assertSame(0xEFBE01C0, $converted);

        $converted = BinaryInteger::asLittleEndian($converted, 32);
        $this->assertSame(0xC001BEEF, $converted);
    }

    public function testAsLittleEndian64Bit()
    {
        $value = 0x1129;
        $converted = BinaryInteger::asLittleEndian($value, 64);
        $this->assertSame(0x2911000000000000, $converted);

        $converted = BinaryInteger::asLittleEndian($converted, 64);
        $this->assertSame(0x1129, $converted);
    }

    public function testAsLittleEndian64BitPattern2()
    {
        $value = 0x0C0010000BEEF000;
        $converted = BinaryInteger::asLittleEndian($value, 64);
        $this->assertSame(0x00F0EE0B0010000C, $converted);

        $converted = BinaryInteger::asLittleEndian($converted, 64);
        $this->assertSame(0x0C0010000BEEF000, $converted);
    }
}
