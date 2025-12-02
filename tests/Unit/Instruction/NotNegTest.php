<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Group3;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for NOT and NEG instructions (Group 3)
 *
 * NOT (F6/2, F7/2): Bitwise NOT (one's complement) - does NOT affect any flags
 * NEG (F6/3, F7/3): Two's complement negation - affects all flags
 *
 * F6 = 8-bit operation
 * F7 = 16/32-bit operation depending on operand size
 */
class NotNegTest extends InstructionTestCase
{
    private Group3 $group3;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->group3 = new Group3($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xF6, 0xF7 => $this->group3,
            default => null,
        };
    }

    // ========================================
    // NOT Tests - Flags should NOT be affected
    // ========================================

    /**
     * Test NOT AL (8-bit) does not affect flags
     * NOT AL: F6 D0 (ModR/M = 11 010 000 = 0xD0, digit=2, rm=AL)
     */
    public function testNotAlDoesNotAffectFlags(): void
    {
        $this->setRegister(RegisterType::EAX, 0x55, 32);

        // Set all flags before NOT
        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(true);
        $this->memoryAccessor->setOverflowFlag(true);
        $this->memoryAccessor->setParityFlag(true);

        // F6 D0 = NOT AL
        $this->executeBytes([0xF6, 0xD0]);

        // Result should be ~0x55 = 0xAA
        $this->assertSame(0xAA, $this->getRegister(RegisterType::EAX, 32) & 0xFF);

        // All flags should remain unchanged
        $this->assertTrue($this->getCarryFlag(), 'CF should remain set');
        $this->assertTrue($this->getZeroFlag(), 'ZF should remain set');
        $this->assertTrue($this->getSignFlag(), 'SF should remain set');
        $this->assertTrue($this->getOverflowFlag(), 'OF should remain set');
        $this->assertTrue($this->memoryAccessor->shouldParityFlag(), 'PF should remain set');
    }

    /**
     * Test NOT AL with flags initially clear
     */
    public function testNotAlWithFlagsClear(): void
    {
        $this->setRegister(RegisterType::EAX, 0xFF, 32);

        // Clear all flags before NOT
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->memoryAccessor->setParityFlag(false);

        $this->executeBytes([0xF6, 0xD0]);

        // Result should be ~0xFF = 0x00
        $this->assertSame(0x00, $this->getRegister(RegisterType::EAX, 32) & 0xFF);

        // All flags should remain unchanged (all clear)
        $this->assertFalse($this->getCarryFlag(), 'CF should remain clear');
        $this->assertFalse($this->getZeroFlag(), 'ZF should remain clear');
        $this->assertFalse($this->getSignFlag(), 'SF should remain clear');
        $this->assertFalse($this->getOverflowFlag(), 'OF should remain clear');
        $this->assertFalse($this->memoryAccessor->shouldParityFlag(), 'PF should remain clear');
    }

    /**
     * Test NOT EAX (32-bit) does not affect flags
     * NOT EAX: F7 D0 (ModR/M = 11 010 000 = 0xD0, digit=2, rm=EAX)
     */
    public function testNotEaxDoesNotAffectFlags(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x12345678, 32);

        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(true);
        $this->memoryAccessor->setOverflowFlag(false);

        $this->executeBytes([0xF7, 0xD0]);

        // Result should be ~0x12345678 = 0xEDCBA987
        $this->assertSame(0xEDCBA987, $this->getRegister(RegisterType::EAX, 32));

        // Flags should remain unchanged
        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertFalse($this->getOverflowFlag());
    }

    /**
     * Test NOT ECX (32-bit)
     * NOT ECX: F7 D1 (ModR/M = 11 010 001 = 0xD1, digit=2, rm=ECX)
     */
    public function testNotEcx(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::ECX, 0x00000000, 32);

        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(true);

        $this->executeBytes([0xF7, 0xD1]);

        // Result should be ~0x00000000 = 0xFFFFFFFF
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::ECX, 32));

        // Flags should remain unchanged
        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
    }

    // ========================================
    // NEG Tests - Flags SHOULD be affected
    // ========================================

    /**
     * Test NEG AL (8-bit) affects flags correctly
     * NEG AL: F6 D8 (ModR/M = 11 011 000 = 0xD8, digit=3, rm=AL)
     */
    public function testNegAlAffectsFlags(): void
    {
        $this->setRegister(RegisterType::EAX, 0x01, 32);

        $this->executeBytes([0xF6, 0xD8]);

        // Result should be -1 = 0xFF
        $this->assertSame(0xFF, $this->getRegister(RegisterType::EAX, 32) & 0xFF);

        // CF should be set (operand was non-zero)
        $this->assertTrue($this->getCarryFlag(), 'CF should be set for non-zero operand');
        // SF should be set (result is negative)
        $this->assertTrue($this->getSignFlag(), 'SF should be set');
        // ZF should be clear
        $this->assertFalse($this->getZeroFlag(), 'ZF should be clear');
    }

    /**
     * Test NEG AL with zero - CF should be clear
     */
    public function testNegAlZero(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00, 32);

        $this->executeBytes([0xF6, 0xD8]);

        // Result should be 0
        $this->assertSame(0x00, $this->getRegister(RegisterType::EAX, 32) & 0xFF);

        // CF should be clear (operand was zero)
        $this->assertFalse($this->getCarryFlag(), 'CF should be clear for zero operand');
        // ZF should be set
        $this->assertTrue($this->getZeroFlag(), 'ZF should be set');
    }

    /**
     * Test NEG AL with most negative value (0x80) - OF should be set
     */
    public function testNegAlMostNegative(): void
    {
        $this->setRegister(RegisterType::EAX, 0x80, 32);

        $this->executeBytes([0xF6, 0xD8]);

        // NEG of 0x80 is still 0x80 (two's complement overflow)
        $this->assertSame(0x80, $this->getRegister(RegisterType::EAX, 32) & 0xFF);

        // CF should be set (operand was non-zero)
        $this->assertTrue($this->getCarryFlag(), 'CF should be set');
        // OF should be set (most negative value)
        $this->assertTrue($this->getOverflowFlag(), 'OF should be set for most negative value');
    }

    /**
     * Test NEG EAX (32-bit) affects flags
     * NEG EAX: F7 D8 (ModR/M = 11 011 000 = 0xD8, digit=3, rm=EAX)
     */
    public function testNegEaxAffectsFlags(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x00000001, 32);

        $this->executeBytes([0xF7, 0xD8]);

        // Result should be -1 = 0xFFFFFFFF
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX, 32));

        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getOverflowFlag());
    }

    /**
     * Test NEG EAX with 32-bit most negative value (0x80000000)
     */
    public function testNegEaxMostNegative(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x80000000, 32);

        $this->executeBytes([0xF7, 0xD8]);

        // NEG of 0x80000000 is still 0x80000000
        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX, 32));

        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag(), 'OF should be set for 32-bit most negative');
    }

    // ========================================
    // Comparison: NOT vs NEG behavior
    // ========================================

    /**
     * Test that NOT and NEG with same input produce different flag states
     */
    public function testNotVsNegFlagDifference(): void
    {
        // First, test NOT
        $this->setRegister(RegisterType::EAX, 0x01, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(true);

        $this->executeBytes([0xF6, 0xD0]); // NOT AL

        // After NOT: result=0xFE, flags unchanged
        $this->assertSame(0xFE, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertFalse($this->getCarryFlag(), 'NOT should not change CF');
        $this->assertTrue($this->getZeroFlag(), 'NOT should not change ZF');

        // Now test NEG with same starting value
        $this->setRegister(RegisterType::EAX, 0x01, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(true);

        $this->executeBytes([0xF6, 0xD8]); // NEG AL

        // After NEG: result=0xFF, flags changed
        $this->assertSame(0xFF, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag(), 'NEG should set CF for non-zero');
        $this->assertFalse($this->getZeroFlag(), 'NEG should clear ZF for non-zero result');
    }
}
