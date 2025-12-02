<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Daa;
use PHPMachineEmulator\Instruction\Intel\x86\Das;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for DAA and DAS instructions
 *
 * DAA (0x27): Decimal Adjust AL after Addition
 * DAS (0x2F): Decimal Adjust AL after Subtraction
 *
 * These are BCD (Binary-Coded Decimal) adjustment instructions.
 */
class DaaDasTest extends InstructionTestCase
{
    private Daa $daa;
    private Das $das;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->daa = new Daa($instructionList);
        $this->das = new Das($instructionList);

        // BCD operations work in real mode or protected mode
        $this->setRealMode16();
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0x27 => $this->daa,
            0x2F => $this->das,
            default => null,
        };
    }

    // ========================================
    // DAA Tests
    // ========================================

    /**
     * Test DAA with no adjustment needed (valid BCD value)
     * 0x15 + 0x23 = 0x38 (no adjustment needed)
     */
    public function testDaaNoAdjustment(): void
    {
        $this->setRegister(RegisterType::EAX, 0x38, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        $this->assertSame(0x38, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    /**
     * Test DAA with lower nibble adjustment (low nibble > 9)
     * After ADD: AL = 0x0C (low nibble = 0xC > 9)
     * After DAA: AL = 0x12 (0x0C + 0x06 = 0x12)
     */
    public function testDaaLowerNibbleAdjustment(): void
    {
        $this->setRegister(RegisterType::EAX, 0x0C, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        $this->assertSame(0x12, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    /**
     * Test DAA with upper nibble adjustment (AL > 0x99)
     * After ADD: AL = 0xA2 (> 0x99)
     * After DAA: AL = 0x02 (0xA2 + 0x60 = 0x102, masked to 0x02)
     */
    public function testDaaUpperNibbleAdjustment(): void
    {
        $this->setRegister(RegisterType::EAX, 0xA2, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        $this->assertSame(0x02, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    /**
     * Test DAA with both nibble adjustments needed
     * After ADD: AL = 0x9C (low > 9 AND > 0x99 after low adj)
     * After DAA: AL = 0x02 (0x9C + 0x06 = 0xA2, then 0xA2 + 0x60 = 0x102)
     */
    public function testDaaBothNibbleAdjustment(): void
    {
        $this->setRegister(RegisterType::EAX, 0x9C, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        // 0x9C + 0x06 = 0xA2 (due to low nibble > 9)
        // 0x9C > 0x99, so add 0x60: 0xA2 + 0x60 = 0x102, masked = 0x02
        $this->assertSame(0x02, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    /**
     * Test DAA adjusts when AF is set (half-carry from addition)
     */
    public function testDaaWithAfSet(): void
    {
        $this->setRegister(RegisterType::EAX, 0x02, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(true);

        $this->executeBytes([0x27]);

        // Low nibble = 2, but AF is set, so add 6: 0x02 + 0x06 = 0x08
        $this->assertSame(0x08, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    /**
     * Test DAA adjusts when CF is set (carry from addition)
     */
    public function testDaaWithCfSet(): void
    {
        $this->setRegister(RegisterType::EAX, 0x02, 32);
        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        // 0x02 + 0x60 = 0x62 (because CF was set)
        $this->assertSame(0x62, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
    }

    /**
     * Test DAA sets ZF when result is zero
     */
    public function testDaaSetsZeroFlag(): void
    {
        $this->setRegister(RegisterType::EAX, 0xA0, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        // 0xA0 > 0x99, so add 0x60: 0xA0 + 0x60 = 0x100, masked = 0x00
        $this->assertSame(0x00, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getZeroFlag());
    }

    /**
     * Test DAA sets SF when result is negative (bit 7 set)
     */
    public function testDaaSetsSignFlag(): void
    {
        $this->setRegister(RegisterType::EAX, 0x79, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        // 0x79: low nibble = 9, not > 9, and no AF, so no low adjustment
        // 0x79 <= 0x99 and no CF, so no high adjustment
        // Result = 0x79, bit 7 = 0
        $this->assertSame(0x79, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertFalse($this->getSignFlag());

        // Now test with result having bit 7 set
        $this->setRegister(RegisterType::EAX, 0x7A, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        // 0x7A: low nibble = A > 9, add 6: 0x7A + 0x06 = 0x80
        $this->assertSame(0x80, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getSignFlag());
    }

    // ========================================
    // DAS Tests
    // ========================================

    /**
     * Test DAS with no adjustment needed (valid BCD value)
     */
    public function testDasNoAdjustment(): void
    {
        $this->setRegister(RegisterType::EAX, 0x38, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x2F]);

        $this->assertSame(0x38, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    /**
     * Test DAS with lower nibble adjustment (low nibble > 9)
     * After SUB: AL = 0x0C (low nibble = 0xC > 9)
     * After DAS: AL = 0x06 (0x0C - 0x06 = 0x06)
     */
    public function testDasLowerNibbleAdjustment(): void
    {
        $this->setRegister(RegisterType::EAX, 0x0C, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x2F]);

        $this->assertSame(0x06, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    /**
     * Test DAS with upper nibble adjustment (original AL > 0x99)
     * After SUB: AL = 0xA2 (> 0x99)
     * After DAS: AL = 0x42 (0xA2 - 0x60 = 0x42)
     */
    public function testDasUpperNibbleAdjustment(): void
    {
        $this->setRegister(RegisterType::EAX, 0xA2, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x2F]);

        $this->assertSame(0x42, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
    }

    /**
     * Test DAS with both nibble adjustments needed
     */
    public function testDasBothNibbleAdjustment(): void
    {
        $this->setRegister(RegisterType::EAX, 0xAC, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x2F]);

        // Low nibble C > 9, subtract 6: 0xAC - 0x06 = 0xA6
        // Original 0xAC > 0x99, subtract 0x60: 0xA6 - 0x60 = 0x46
        $this->assertSame(0x46, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    /**
     * Test DAS adjusts when AF is set (borrow in lower nibble)
     */
    public function testDasWithAfSet(): void
    {
        $this->setRegister(RegisterType::EAX, 0x02, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(true);

        $this->executeBytes([0x2F]);

        // Low nibble = 2, but AF is set, so subtract 6: 0x02 - 0x06 = 0xFC
        // And since 0x02 < 6, CF is set
        $this->assertSame(0xFC, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    /**
     * Test DAS adjusts when CF is set (borrow from subtraction)
     */
    public function testDasWithCfSet(): void
    {
        $this->setRegister(RegisterType::EAX, 0x62, 32);
        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x2F]);

        // Original CF was set, so subtract 0x60: 0x62 - 0x60 = 0x02
        $this->assertSame(0x02, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
    }

    /**
     * Test DAS sets ZF when result is zero
     */
    public function testDasSetsZeroFlag(): void
    {
        $this->setRegister(RegisterType::EAX, 0x60, 32);
        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x2F]);

        // CF set, subtract 0x60: 0x60 - 0x60 = 0x00
        $this->assertSame(0x00, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getZeroFlag());
    }

    /**
     * Test DAS sets SF when result has bit 7 set
     */
    public function testDasSetsSignFlag(): void
    {
        $this->setRegister(RegisterType::EAX, 0x8A, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x2F]);

        // Low nibble A > 9, subtract 6: 0x8A - 0x06 = 0x84
        // Bit 7 is set
        $this->assertSame(0x84, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getSignFlag());
    }

    // ========================================
    // BCD Addition/Subtraction Integration
    // ========================================

    /**
     * Test BCD addition: 35 + 48 = 83
     * Simulates: AL = 0x35 + 0x48 = 0x7D, then DAA -> 0x83
     */
    public function testBcdAddition(): void
    {
        // After ADD 0x35 + 0x48 = 0x7D
        $this->setRegister(RegisterType::EAX, 0x7D, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        // D > 9, add 6: 0x7D + 0x06 = 0x83
        $this->assertSame(0x83, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
    }

    /**
     * Test BCD addition with carry: 85 + 27 = 112 (12 with carry)
     * Simulates: AL = 0x85 + 0x27 = 0xAC, then DAA -> 0x12 with CF
     */
    public function testBcdAdditionWithCarry(): void
    {
        $this->setRegister(RegisterType::EAX, 0xAC, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x27]);

        // C > 9, add 6: 0xAC + 0x06 = 0xB2
        // 0xAC > 0x99, add 0x60: 0xB2 + 0x60 = 0x112, masked = 0x12
        $this->assertSame(0x12, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
    }

    /**
     * Test BCD subtraction: 83 - 48 = 35
     * Simulates: AL = 0x83 - 0x48 = 0x3B, then DAS -> 0x35
     */
    public function testBcdSubtraction(): void
    {
        $this->setRegister(RegisterType::EAX, 0x3B, 32);
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);

        $this->executeBytes([0x2F]);

        // B > 9, subtract 6: 0x3B - 0x06 = 0x35
        $this->assertSame(0x35, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
    }
}
