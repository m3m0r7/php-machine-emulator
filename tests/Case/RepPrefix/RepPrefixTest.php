<?php

declare(strict_types=1);

namespace Tests\Case\RepPrefix;

use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\OptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\CreateApplication;
use Tests\Utils\BootableFileStream;

/**
 * Integration tests for REP prefix with various override prefixes.
 *
 * Tests:
 * 1. Basic REP MOVSB (no override)
 * 2. REP ES: MOVSB (F3 26 A4) - segment override
 * 3. REP STOSD (F3 66 AB) - operand size override
 * 4. REP STOSD (F3 66 67 AB) - operand + address size override
 * 5. REPNE SCASB (F2 AE) - REPNE prefix
 *
 * Each test writes a marker byte to 0xB000+N on success (0xNN) or failure (0xEN).
 * Final success marker: 0xB00F = 0xFF
 */
class RepPrefixTest extends TestCase
{
    use CreateApplication;

    private const MARKER_BASE = 0xB000;

    public static function repPrefixDataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/RepPrefix.o', 0x7C00);
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('repPrefixDataProvider')]
    public function testRepPrefixOperations(MachineInterface $machine, OptionInterface $option): void
    {
        try {
            self::bootBios($machine);
        } catch (ExitException|HaltException) {
            // Expected - test halts after completion
        }

        $memoryAccessor = $machine->runtime()->memoryAccessor();

        // Check start marker
        $startMarker = $memoryAccessor->readRawByte(self::MARKER_BASE);
        $this->assertSame(
            0xAA,
            $startMarker,
            'Start marker should be 0xAA'
        );

        // Test 1: Basic REP MOVSB
        $test1 = $memoryAccessor->readRawByte(self::MARKER_BASE + 1);
        $this->assertSame(
            0x11,
            $test1,
            sprintf('Test 1 (Basic REP MOVSB) failed: marker=0x%02X (expected 0x11)', $test1)
        );

        // Test 2: REP ES: MOVSB (segment override)
        $test2 = $memoryAccessor->readRawByte(self::MARKER_BASE + 2);
        $this->assertSame(
            0x22,
            $test2,
            sprintf('Test 2 (REP ES: MOVSB) failed: marker=0x%02X (expected 0x22)', $test2)
        );

        // Test 3: REP STOSD with 0x66 prefix
        $test3 = $memoryAccessor->readRawByte(self::MARKER_BASE + 3);
        $this->assertSame(
            0x33,
            $test3,
            sprintf('Test 3 (REP STOSD with 0x66) failed: marker=0x%02X (expected 0x33)', $test3)
        );

        // Test 4: REP STOSD with 0x66 + 0x67 prefix
        $test4 = $memoryAccessor->readRawByte(self::MARKER_BASE + 4);
        $this->assertSame(
            0x44,
            $test4,
            sprintf('Test 4 (REP STOSD with 0x66+0x67) failed: marker=0x%02X (expected 0x44)', $test4)
        );

        // Test 5: REPNE SCASB
        $test5 = $memoryAccessor->readRawByte(self::MARKER_BASE + 5);
        $this->assertSame(
            0x55,
            $test5,
            sprintf('Test 5 (REPNE SCASB) failed: marker=0x%02X (expected 0x55)', $test5)
        );

        // Final success marker
        $finalMarker = $memoryAccessor->readRawByte(self::MARKER_BASE + 15);
        $this->assertSame(
            0xFF,
            $finalMarker,
            sprintf('Final success marker should be 0xFF, got 0x%02X', $finalMarker)
        );
    }

    #[DataProvider('repPrefixDataProvider')]
    public function testRepMovsbBasic(MachineInterface $machine, OptionInterface $option): void
    {
        try {
            self::bootBios($machine);
        } catch (ExitException|HaltException) {
        }

        $memoryAccessor = $machine->runtime()->memoryAccessor();

        // Verify destination memory was correctly filled by REP MOVSB
        // Source: 0x1000 had 0x11, 0x22, 0x33, 0x44
        // Destination: 0x8000 should have same values
        $dst = 0x8000;
        $this->assertSame(0x11, $memoryAccessor->readRawByte($dst));
        $this->assertSame(0x22, $memoryAccessor->readRawByte($dst + 1));
        $this->assertSame(0x33, $memoryAccessor->readRawByte($dst + 2));
        $this->assertSame(0x44, $memoryAccessor->readRawByte($dst + 3));
    }

    #[DataProvider('repPrefixDataProvider')]
    public function testRepStosdWithOperandSizePrefix(MachineInterface $machine, OptionInterface $option): void
    {
        try {
            self::bootBios($machine);
        } catch (ExitException|HaltException) {
        }

        $memoryAccessor = $machine->runtime()->memoryAccessor();

        // REP STOSD should have zeroed 0x8030-0x8037 (2 dwords)
        $dst = 0x8030;
        for ($i = 0; $i < 8; $i++) {
            $this->assertSame(
                0x00,
                $memoryAccessor->readRawByte($dst + $i),
                sprintf('Byte at 0x%04X should be 0x00', $dst + $i)
            );
        }
    }

    #[DataProvider('repPrefixDataProvider')]
    public function testRepStosdWithBothPrefixes(MachineInterface $machine, OptionInterface $option): void
    {
        try {
            self::bootBios($machine);
        } catch (ExitException|HaltException) {
        }

        $memoryAccessor = $machine->runtime()->memoryAccessor();

        // REP STOSD with 0x66+0x67 should have written 0x12345678 to 0x8060-0x8067
        $dst = 0x8060;

        // Read as 32-bit values (little-endian)
        $dword1 = $memoryAccessor->readRawByte($dst)
            | ($memoryAccessor->readRawByte($dst + 1) << 8)
            | ($memoryAccessor->readRawByte($dst + 2) << 16)
            | ($memoryAccessor->readRawByte($dst + 3) << 24);

        $dword2 = $memoryAccessor->readRawByte($dst + 4)
            | ($memoryAccessor->readRawByte($dst + 5) << 8)
            | ($memoryAccessor->readRawByte($dst + 6) << 16)
            | ($memoryAccessor->readRawByte($dst + 7) << 24);

        $this->assertSame(0x12345678, $dword1, 'First dword should be 0x12345678');
        $this->assertSame(0x12345678, $dword2, 'Second dword should be 0x12345678');
    }
}
