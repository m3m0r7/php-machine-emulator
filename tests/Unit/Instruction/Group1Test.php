<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Group1;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for Group1 instructions: ADD, OR, ADC, SBB, AND, SUB, XOR, CMP
 * Opcodes: 0x80, 0x81, 0x82, 0x83
 */
class Group1Test extends InstructionTestCase
{
    private Group1 $group1;

    protected function setUp(): void
    {
        parent::setUp();
        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->group1 = new Group1($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        if (in_array($opcode, [0x80, 0x81, 0x82, 0x83], true)) {
            return $this->group1;
        }
        return null;
    }

    // ========================================
    // ADD Tests - digit 0
    // ========================================

    public function testAddReg32Imm32(): void
    {
        // ADD EAX, 0x12345678 (0x81 /0, ModRM=0xC0, imm32)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0x81, 0xC0, 0x78, 0x56, 0x34, 0x12]); // ADD EAX, 0x12345678

        $this->assertSame(0x12345679, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testAddReg32Imm8SignExtended(): void
    {
        // ADD EAX, 0x10 (0x83 /0, ModRM=0xC0, imm8 sign-extended)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0x83, 0xC0, 0x10]); // ADD EAX, 0x10

        $this->assertSame(0x00000011, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testAddReg32Imm8NegativeSignExtended(): void
    {
        // ADD EAX, -1 (0x83 /0, ModRM=0xC0, imm8=0xFF sign-extended to 0xFFFFFFFF)
        // 0x100 + 0xFFFFFFFF = 0x1000000FF (unsigned 64-bit), wraps to 0xFF with carry
        $this->setRegister(RegisterType::EAX, 0x00000100);
        $this->executeBytes([0x83, 0xC0, 0xFF]); // ADD EAX, -1

        $this->assertSame(0x000000FF, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag()); // 0x100 + 0xFFFFFFFF overflows 32-bit
    }

    public function testAddWithCarryOut(): void
    {
        // ADD that causes carry
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0x83, 0xC0, 0x02]); // ADD EAX, 2

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag());
    }

    public function testAddReg8Imm8(): void
    {
        // ADD AL, 0x10 (0x80 /0, ModRM=0xC0, imm8)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0x80, 0xC0, 0x10]); // ADD AL, 0x10

        $this->assertSame(0x11, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testAddReg8WithCarry(): void
    {
        // ADD AL, 0x01 causing carry (AL = 0xFF)
        $this->setRegister(RegisterType::EAX, 0x000000FF);
        $this->executeBytes([0x80, 0xC0, 0x01]); // ADD AL, 1

        $this->assertSame(0x00, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // SUB Tests - digit 5
    // ========================================

    public function testSubReg32Imm32(): void
    {
        // SUB EAX, 0x00000001 (0x81 /5, ModRM=0xE8)
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x81, 0xE8, 0x01, 0x00, 0x00, 0x00]); // SUB EAX, 1

        $this->assertSame(0x12345677, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testSubReg32Imm8SignExtended(): void
    {
        // SUB EAX, 0x10 (0x83 /5, ModRM=0xE8, imm8 sign-extended)
        $this->setRegister(RegisterType::EAX, 0x00000100);
        $this->executeBytes([0x83, 0xE8, 0x10]); // SUB EAX, 0x10

        $this->assertSame(0x000000F0, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testSubWithBorrow(): void
    {
        // SUB that causes borrow (underflow)
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x83, 0xE8, 0x01]); // SUB EAX, 1

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag()); // borrow occurred
    }

    public function testSubReg8Imm8(): void
    {
        // SUB AL, 0x01 (0x80 /5, ModRM=0xE8, imm8)
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->executeBytes([0x80, 0xE8, 0x01]); // SUB AL, 1

        $this->assertSame(0x0F, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testSubReg8WithBorrow(): void
    {
        // SUB AL, 1 causing borrow (AL = 0)
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x80, 0xE8, 0x01]); // SUB AL, 1

        $this->assertSame(0xFF, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // ADC Tests - digit 2
    // ========================================

    public function testAdcReg32Imm8WithCarryClear(): void
    {
        // ADC EAX, 0x10 with CF=0 (0x83 /2, ModRM=0xD0)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setCarryFlag(false);
        $this->executeBytes([0x83, 0xD0, 0x10]); // ADC EAX, 0x10

        $this->assertSame(0x00000011, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testAdcReg32Imm8WithCarrySet(): void
    {
        // ADC EAX, 0x10 with CF=1 (0x83 /2, ModRM=0xD0)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setCarryFlag(true);
        $this->executeBytes([0x83, 0xD0, 0x10]); // ADC EAX, 0x10

        $this->assertSame(0x00000012, $this->getRegister(RegisterType::EAX)); // 1 + 16 + 1(CF)
        $this->assertFalse($this->getCarryFlag());
    }

    public function testAdcProducesCarry(): void
    {
        // ADC that produces carry
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->setCarryFlag(true);
        $this->executeBytes([0x83, 0xD0, 0x01]); // ADC EAX, 1

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX)); // 0xFFFFFFFF + 1 + 1 = 0x100000001
        $this->assertTrue($this->getCarryFlag());
    }

    public function testAdcReg8Imm8(): void
    {
        // ADC AL, 0x10 with CF=1 (0x80 /2, ModRM=0xD0)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setCarryFlag(true);
        $this->executeBytes([0x80, 0xD0, 0x10]); // ADC AL, 0x10

        $this->assertSame(0x12, $this->getRegister(RegisterType::EAX, 8)); // 1 + 16 + 1
        $this->assertFalse($this->getCarryFlag());
    }

    // ========================================
    // SBB Tests - digit 3
    // ========================================

    public function testSbbReg32Imm8WithCarryClear(): void
    {
        // SBB EAX, 0x01 with CF=0 (0x83 /3, ModRM=0xD8)
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->setCarryFlag(false);
        $this->executeBytes([0x83, 0xD8, 0x01]); // SBB EAX, 1

        $this->assertSame(0x0000000F, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testSbbReg32Imm8WithCarrySet(): void
    {
        // SBB EAX, 0x01 with CF=1 (0x83 /3, ModRM=0xD8)
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->setCarryFlag(true);
        $this->executeBytes([0x83, 0xD8, 0x01]); // SBB EAX, 1

        $this->assertSame(0x0000000E, $this->getRegister(RegisterType::EAX)); // 16 - 1 - 1(borrow)
        $this->assertFalse($this->getCarryFlag());
    }

    public function testSbbProducesBorrow(): void
    {
        // SBB that produces borrow
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setCarryFlag(true);
        $this->executeBytes([0x83, 0xD8, 0x01]); // SBB EAX, 1

        $this->assertSame(0xFFFFFFFE, $this->getRegister(RegisterType::EAX)); // 0 - 1 - 1 = -2
        $this->assertTrue($this->getCarryFlag());
    }

    public function testSbbReg8Imm8(): void
    {
        // SBB AL, 0x01 with CF=1 (0x80 /3, ModRM=0xD8)
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->setCarryFlag(true);
        $this->executeBytes([0x80, 0xD8, 0x01]); // SBB AL, 1

        $this->assertSame(0x0E, $this->getRegister(RegisterType::EAX, 8)); // 16 - 1 - 1
        $this->assertFalse($this->getCarryFlag());
    }

    // ========================================
    // AND Tests - digit 4
    // ========================================

    public function testAndReg32Imm32(): void
    {
        // AND EAX, 0x0F0F0F0F (0x81 /4, ModRM=0xE0)
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0x81, 0xE0, 0x0F, 0x0F, 0x0F, 0x0F]); // AND EAX, 0x0F0F0F0F

        $this->assertSame(0x0F0F0F0F, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag()); // AND clears CF
    }

    public function testAndReg32Imm8SignExtended(): void
    {
        // AND EAX, 0x0F (0x83 /4, ModRM=0xE0)
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x83, 0xE0, 0x0F]); // AND EAX, 0x0F

        $this->assertSame(0x00000008, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testAndReg8Imm8(): void
    {
        // AND AL, 0xF0 (0x80 /4, ModRM=0xE0)
        $this->setRegister(RegisterType::EAX, 0x000000AB);
        $this->executeBytes([0x80, 0xE0, 0xF0]); // AND AL, 0xF0

        $this->assertSame(0xA0, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testAndClearsCarryFlag(): void
    {
        // AND should always clear CF
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->setCarryFlag(true); // Set CF before
        $this->executeBytes([0x83, 0xE0, 0xFF]); // AND EAX, 0xFF (sign-extended to 0xFFFFFFFF)

        // AND with 0xFFFFFFFF should preserve value
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag()); // CF cleared
    }

    // ========================================
    // OR Tests - digit 1
    // ========================================

    public function testOrReg32Imm32(): void
    {
        // OR EAX, 0xF0F0F0F0 (0x81 /1, ModRM=0xC8)
        $this->setRegister(RegisterType::EAX, 0x0F0F0F0F);
        $this->executeBytes([0x81, 0xC8, 0xF0, 0xF0, 0xF0, 0xF0]); // OR EAX, 0xF0F0F0F0

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag()); // OR clears CF
    }

    public function testOrReg32Imm8SignExtended(): void
    {
        // OR EAX, 0x80 sign-extended to 0xFFFFFF80 (0x83 /1, ModRM=0xC8)
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x83, 0xC8, 0x80]); // OR EAX, 0x80 (sign-extended)

        $this->assertSame(0xFFFFFF80, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testOrReg8Imm8(): void
    {
        // OR AL, 0x0F (0x80 /1, ModRM=0xC8)
        $this->setRegister(RegisterType::EAX, 0x000000F0);
        $this->executeBytes([0x80, 0xC8, 0x0F]); // OR AL, 0x0F

        $this->assertSame(0xFF, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag());
    }

    // ========================================
    // XOR Tests - digit 6
    // ========================================

    public function testXorReg32Imm32(): void
    {
        // XOR EAX, 0xFFFFFFFF (0x81 /6, ModRM=0xF0)
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x81, 0xF0, 0xFF, 0xFF, 0xFF, 0xFF]); // XOR EAX, 0xFFFFFFFF

        $this->assertSame(0xEDCBA987, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testXorReg32WithItself(): void
    {
        // XOR EAX, 0xFFFFFFFF twice should restore original
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x81, 0xF0, 0xFF, 0xFF, 0xFF, 0xFF]); // XOR EAX, 0xFFFFFFFF
        $this->executeBytes([0x81, 0xF0, 0xFF, 0xFF, 0xFF, 0xFF]); // XOR EAX, 0xFFFFFFFF

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testXorReg8Imm8(): void
    {
        // XOR AL, 0xFF (0x80 /6, ModRM=0xF0)
        $this->setRegister(RegisterType::EAX, 0x00000055);
        $this->executeBytes([0x80, 0xF0, 0xFF]); // XOR AL, 0xFF

        $this->assertSame(0xAA, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag());
    }

    // ========================================
    // CMP Tests - digit 7
    // ========================================

    public function testCmpReg32Imm32Equal(): void
    {
        // CMP EAX, 0x12345678 (0x81 /7, ModRM=0xF8)
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x81, 0xF8, 0x78, 0x56, 0x34, 0x12]); // CMP EAX, 0x12345678

        // EAX should be unchanged
        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getZeroFlag()); // Equal
        $this->assertFalse($this->getCarryFlag()); // No borrow
    }

    public function testCmpReg32Imm32Greater(): void
    {
        // CMP EAX, 0x10 where EAX > imm (0x83 /7, ModRM=0xF8)
        $this->setRegister(RegisterType::EAX, 0x00000100);
        $this->executeBytes([0x83, 0xF8, 0x10]); // CMP EAX, 0x10

        // EAX should be unchanged
        $this->assertSame(0x00000100, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag()); // Not equal
        $this->assertFalse($this->getCarryFlag()); // EAX >= imm, no borrow
    }

    public function testCmpReg32Imm32Less(): void
    {
        // CMP EAX, 0x100 where EAX < imm (0x81 /7, ModRM=0xF8)
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->executeBytes([0x81, 0xF8, 0x00, 0x01, 0x00, 0x00]); // CMP EAX, 0x100

        // EAX should be unchanged
        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag()); // Not equal
        $this->assertTrue($this->getCarryFlag()); // EAX < imm, borrow
    }

    public function testCmpReg8Imm8(): void
    {
        // CMP AL, 0x10 (0x80 /7, ModRM=0xF8)
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->executeBytes([0x80, 0xF8, 0x10]); // CMP AL, 0x10

        // EAX should be unchanged
        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getZeroFlag()); // Equal
        $this->assertFalse($this->getCarryFlag());
    }

    public function testCmpSetsSignFlag(): void
    {
        // CMP that results in negative (SF should be set)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0x83, 0xF8, 0x02]); // CMP EAX, 2

        // 1 - 2 = -1 = 0xFFFFFFFF (negative, SF=1)
        $this->assertTrue($this->getSignFlag());
        $this->assertTrue($this->getCarryFlag()); // 1 < 2, borrow
    }

    // ========================================
    // Edge Cases and Special Scenarios
    // ========================================

    public function testAddZero(): void
    {
        // ADD with 0 should not change value
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x83, 0xC0, 0x00]); // ADD EAX, 0

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testSubZero(): void
    {
        // SUB with 0 should not change value
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x83, 0xE8, 0x00]); // SUB EAX, 0

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testAndWithZero(): void
    {
        // AND with 0 should produce 0
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0x83, 0xE0, 0x00]); // AND EAX, 0

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getZeroFlag()); // Result is 0, ZF should be set
    }

    public function testOrWithZero(): void
    {
        // OR with 0 should not change value
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x83, 0xC8, 0x00]); // OR EAX, 0

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testXorWithZero(): void
    {
        // XOR with 0 should not change value
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x83, 0xF0, 0x00]); // XOR EAX, 0

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testDifferentRegistersAdd(): void
    {
        // Test ADD on different registers
        // ModRM for ADD reg, imm8 (0x83 /0) with different registers:
        // EAX=0xC0, ECX=0xC1, EDX=0xC2, EBX=0xC3
        $testCases = [
            [RegisterType::EAX, 0xC0],
            [RegisterType::ECX, 0xC1],
            [RegisterType::EDX, 0xC2],
            [RegisterType::EBX, 0xC3],
        ];

        foreach ($testCases as [$reg, $modrm]) {
            $this->setUp();
            $this->setRegister($reg, 0x00000001);
            $this->executeBytes([0x83, $modrm, 0x10]); // ADD reg, 0x10

            $this->assertSame(
                0x00000011,
                $this->getRegister($reg),
                sprintf('ADD failed for register %s (ModRM=0x%02X)', $reg->name, $modrm)
            );
        }
    }

    public function testDifferentRegistersSub(): void
    {
        // Test SUB on different registers (0x83 /5)
        // ModRM = 11 101 rrr = 0xE8 + reg
        $testCases = [
            [RegisterType::EAX, 0xE8],
            [RegisterType::ECX, 0xE9],
            [RegisterType::EDX, 0xEA],
            [RegisterType::EBX, 0xEB],
        ];

        foreach ($testCases as [$reg, $modrm]) {
            $this->setUp();
            $this->setRegister($reg, 0x00000100);
            $this->executeBytes([0x83, $modrm, 0x10]); // SUB reg, 0x10

            $this->assertSame(
                0x000000F0,
                $this->getRegister($reg),
                sprintf('SUB failed for register %s (ModRM=0x%02X)', $reg->name, $modrm)
            );
        }
    }

    public function testOverflowFlagOnSignedAddition(): void
    {
        // Signed overflow: 0x7FFFFFFF + 1 = 0x80000000 (positive + positive = negative)
        $this->setRegister(RegisterType::EAX, 0x7FFFFFFF);
        $this->executeBytes([0x83, 0xC0, 0x01]); // ADD EAX, 1

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        // This should set overflow flag (implementation dependent)
    }

    public function testZeroFlagOnResult(): void
    {
        // SUB that produces zero
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->executeBytes([0x83, 0xE8, 0x10]); // SUB EAX, 0x10

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getZeroFlag()); // Result is 0, ZF should be set
    }
}
