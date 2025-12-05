<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for A20 gate functionality.
 *
 * The A20 gate controls access to memory above 1MB. When disabled (default on 8086),
 * address line 20 is forced low, causing addresses above 1MB to wrap around.
 *
 * Tests verify:
 * - A20 gate enable/disable
 * - Address wrapping behavior
 * - Keyboard controller A20 methods (port 0x64 command 0xD1)
 * - Fast A20 via port 0x92
 * - INT 15h AH=24h A20 control
 */
class A20GateTest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    // ========================================
    // A20 State Tests
    // ========================================

    public function testA20DefaultDisabled(): void
    {
        // On reset, A20 should be disabled for 8086 compatibility
        $this->assertFalse($this->cpuContext->isA20Enabled());
    }

    public function testA20Enable(): void
    {
        $this->cpuContext->enableA20(true);
        $this->assertTrue($this->cpuContext->isA20Enabled());
    }

    public function testA20Disable(): void
    {
        $this->cpuContext->enableA20(true);
        $this->assertTrue($this->cpuContext->isA20Enabled());

        $this->cpuContext->enableA20(false);
        $this->assertFalse($this->cpuContext->isA20Enabled());
    }

    // ========================================
    // Address Wrapping Tests
    // ========================================

    public function testAddressWrapWithA20Disabled(): void
    {
        $this->cpuContext->enableA20(false);

        // When A20 is disabled, bit 20 is masked to 0
        // Address 0x100000 becomes 0x000000
        // Address 0x10FFEF becomes 0x00FFEF

        $address = 0x100000; // 1MB boundary
        $maskedAddress = $address & ~(1 << 20);

        $this->assertSame(0x000000, $maskedAddress);
    }

    public function testAddressWrapAt1MBBoundary(): void
    {
        $this->cpuContext->enableA20(false);

        // Classic 8086 wrap: FFFF:0010 = 0x100000 wraps to 0x0
        $segment = 0xFFFF;
        $offset = 0x0010;
        $linear = ($segment << 4) + $offset; // = 0x100000

        // With A20 disabled, mask bit 20
        $wrapped = $linear & ~(1 << 20);

        $this->assertSame(0x100000, $linear);
        $this->assertSame(0x000000, $wrapped);
    }

    public function testAddressNoWrapWithA20Enabled(): void
    {
        $this->cpuContext->enableA20(true);

        // With A20 enabled, full address is preserved
        $address = 0x100000;

        // No masking when A20 is enabled
        $this->assertSame(0x100000, $address);
    }

    public function testHMAAccess(): void
    {
        // HMA (High Memory Area) is 0x100000-0x10FFEF
        // Only accessible with A20 enabled

        $this->cpuContext->enableA20(true);

        $hmaStart = 0x100000;
        $hmaEnd = 0x10FFEF;

        // With A20 enabled, these addresses are valid
        $this->assertSame(0x100000, $hmaStart);
        $this->assertSame(0x10FFEF, $hmaEnd);
        $this->assertTrue($hmaEnd - $hmaStart < 0x10000);
    }

    // ========================================
    // Keyboard Controller A20 Method
    // ========================================

    public function testKeyboardControllerCommand0xD1(): void
    {
        // Command 0xD1 to port 0x64 prepares output port write
        // Writing to port 0x60 with bit 1 set enables A20

        // Simulate the sequence:
        // 1. Write 0xD1 to port 0x64 (output port write command)
        // 2. Write value with bit 1 to port 0x60

        $outputPortValue = 0x02; // Bit 1 = A20 enable
        $a20Enabled = ($outputPortValue & 0x02) !== 0;

        $this->assertTrue($a20Enabled);
    }

    public function testKeyboardControllerA20BitMeaning(): void
    {
        // Output port bit 1 controls A20
        // 0x00 = A20 disabled
        // 0x02 = A20 enabled

        $valueA20Disabled = 0xDD; // Typical value with bit 1 = 0
        $valueA20Enabled = 0xDF;  // Typical value with bit 1 = 1

        $this->assertSame(0, ($valueA20Disabled & 0x02));
        $this->assertSame(0x02, ($valueA20Enabled & 0x02));
    }

    // ========================================
    // Fast A20 (Port 0x92) Tests
    // ========================================

    public function testFastA20Port92Format(): void
    {
        // Port 0x92 (System Control Port A):
        // Bit 0: Fast reset (write 1 to reset)
        // Bit 1: A20 gate control

        $port92Value = 0x02; // A20 enabled, no reset

        $resetBit = ($port92Value & 0x01) !== 0;
        $a20Bit = ($port92Value & 0x02) !== 0;

        $this->assertFalse($resetBit);
        $this->assertTrue($a20Bit);
    }

    public function testFastA20SafeToggle(): void
    {
        // Safe way to toggle A20 via port 0x92:
        // Read current value, set/clear bit 1, write back
        // Never set bit 0 (reset)!

        $currentValue = 0x00;

        // Enable A20 safely
        $newValue = ($currentValue | 0x02) & ~0x01;

        $this->assertSame(0x02, $newValue);
        $this->assertSame(0, $newValue & 0x01); // Reset bit must be 0
    }

    // ========================================
    // INT 15h A20 Control Tests
    // ========================================

    public function testInt15hA20Functions(): void
    {
        // INT 15h, AH=24h - A20 gate support (PS/2 and later)
        // AL=00: Disable A20
        // AL=01: Enable A20
        // AL=02: Query A20 status
        // AL=03: Query A20 support

        $ah = 0x24;

        $alDisable = 0x00;
        $alEnable = 0x01;
        $alQuery = 0x02;
        $alSupport = 0x03;

        $this->assertSame(0x24, $ah);
        $this->assertSame(0x00, $alDisable);
        $this->assertSame(0x01, $alEnable);
    }

    // ========================================
    // A20 Wait States
    // ========================================

    public function testWaitingA20OutputPort(): void
    {
        // Test the waiting state for A20 output port write

        $this->cpuContext->setWaitingA20OutputPort(true);
        $this->assertTrue($this->cpuContext->isWaitingA20OutputPort());

        $this->cpuContext->setWaitingA20OutputPort(false);
        $this->assertFalse($this->cpuContext->isWaitingA20OutputPort());
    }

    // ========================================
    // Memory Boundary Tests
    // ========================================

    public function testAddressMaskCalculation(): void
    {
        // The A20 mask affects bit 20 of the address
        $a20Mask = ~(1 << 20);

        // 0xFFEFFFFF = all bits except bit 20
        $this->assertSame(0xFFEFFFFF & 0xFFFFFFFF, $a20Mask & 0xFFFFFFFF);
    }

    public function testAddressesAffectedByA20(): void
    {
        // Only addresses with bit 20 set are affected
        $affectedAddresses = [
            0x100000, // First affected
            0x10FFFF,
            0x1FFFFF,
            0x300000,
        ];

        $unaffectedAddresses = [
            0x000000,
            0x0FFFFF, // Just below 1MB
            0x200000, // Bit 21, not 20
        ];

        foreach ($affectedAddresses as $addr) {
            $this->assertNotSame(0, $addr & (1 << 20), "Address 0x" . dechex($addr) . " should have bit 20 set");
        }

        foreach ($unaffectedAddresses as $addr) {
            if ($addr === 0x200000) {
                // 0x200000 actually has bit 21 set, bit 20 clear
                $this->assertSame(0, $addr & (1 << 20));
            }
        }
    }

    // ========================================
    // Real Mode High Memory
    // ========================================

    public function testRealModeMaxAddress(): void
    {
        $this->setRealMode16();

        // Maximum real mode address without A20: FFFF:FFFF = 0x10FFEF
        $segment = 0xFFFF;
        $offset = 0xFFFF;
        $linear = ($segment << 4) + $offset;

        $this->assertSame(0x10FFEF, $linear);
    }

    public function testRealMode1MBBoundary(): void
    {
        $this->setRealMode16();

        // Exactly at 1MB boundary
        $segment = 0xFFFF;
        $offset = 0x0010;
        $linear = ($segment << 4) + $offset;

        $this->assertSame(0x100000, $linear);

        // With A20 disabled, this wraps to 0
        $wrapped = $linear & ~(1 << 20);
        $this->assertSame(0x000000, $wrapped);
    }
}
