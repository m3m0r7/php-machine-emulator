<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Group3;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for Group3 instructions: TEST, NOT, NEG, MUL, IMUL, DIV, IDIV
 * Opcodes: 0xF6 (8-bit), 0xF7 (16/32-bit)
 */
class Group3Test extends InstructionTestCase
{
    private Group3 $group3;

    protected function setUp(): void
    {
        parent::setUp();
        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->group3 = new Group3($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        if (in_array($opcode, [0xF6, 0xF7], true)) {
            return $this->group3;
        }
        return null;
    }

    // ========================================
    // TEST Tests - digit 0
    // ========================================

    public function testTestReg8Imm8(): void
    {
        // TEST AL, 0x0F (0xF6 /0, ModRM=0xC0, imm8)
        $this->setRegister(RegisterType::EAX, 0x000000FF);
        $this->executeBytes([0xF6, 0xC0, 0x0F]); // TEST AL, 0x0F

        // TEST doesn't modify the operand
        $this->assertSame(0xFF, $this->getRegister(RegisterType::EAX, 8));
        // ZF = (0xFF & 0x0F) == 0? No, ZF=0
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag()); // TEST clears CF
    }

    public function testTestReg8Imm8ZeroResult(): void
    {
        // TEST AL, 0xF0 where AL=0x0F should produce zero
        $this->setRegister(RegisterType::EAX, 0x0000000F);
        $this->executeBytes([0xF6, 0xC0, 0xF0]); // TEST AL, 0xF0

        $this->assertSame(0x0F, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getZeroFlag()); // 0x0F & 0xF0 = 0
    }

    public function testTestReg32Imm32(): void
    {
        // TEST EAX, 0x0000FFFF (0xF7 /0, ModRM=0xC0, imm32)
        $this->setRegister(RegisterType::EAX, 0xFFFF0000);
        $this->executeBytes([0xF7, 0xC0, 0xFF, 0xFF, 0x00, 0x00]); // TEST EAX, 0x0000FFFF

        $this->assertSame(0xFFFF0000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getZeroFlag()); // 0xFFFF0000 & 0x0000FFFF = 0
    }

    // ========================================
    // NOT Tests - digit 2
    // ========================================

    public function testNotReg8(): void
    {
        // NOT AL (0xF6 /2, ModRM=0xD0)
        $this->setRegister(RegisterType::EAX, 0x00000055);
        $this->executeBytes([0xF6, 0xD0]); // NOT AL

        $this->assertSame(0xAA, $this->getRegister(RegisterType::EAX, 8));
        // NOT doesn't affect flags
    }

    public function testNotReg32(): void
    {
        // NOT EAX (0xF7 /2, ModRM=0xD0)
        $this->setRegister(RegisterType::EAX, 0x0F0F0F0F);
        $this->executeBytes([0xF7, 0xD0]); // NOT EAX

        $this->assertSame(0xF0F0F0F0, $this->getRegister(RegisterType::EAX));
    }

    public function testNotAllZeros(): void
    {
        // NOT 0x00000000 should produce 0xFFFFFFFF
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0xF7, 0xD0]); // NOT EAX

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    public function testNotAllOnes(): void
    {
        // NOT 0xFFFFFFFF should produce 0x00000000
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0xF7, 0xD0]); // NOT EAX

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // NEG Tests - digit 3
    // ========================================

    public function testNegReg8Positive(): void
    {
        // NEG AL (0xF6 /3, ModRM=0xD8)
        // AL = 1, after NEG => -1 = 0xFF
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0xF6, 0xD8]); // NEG AL

        $this->assertSame(0xFF, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getCarryFlag()); // NEG sets CF if operand != 0
    }

    public function testNegReg8Zero(): void
    {
        // NEG AL where AL = 0
        // NEG 0 => 0, CF should be cleared
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0xF6, 0xD8]); // NEG AL

        $this->assertSame(0x00, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag()); // CF=0 when operand is 0
    }

    public function testNegReg32(): void
    {
        // NEG EAX (0xF7 /3, ModRM=0xD8)
        // EAX = 0x00000001, after NEG => 0xFFFFFFFF (-1)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag());
    }

    public function testNegReg32Large(): void
    {
        // NEG EAX where EAX = 0x80000000 (minimum signed 32-bit)
        // NEG 0x80000000 => 0x80000000 (overflow to itself)
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // NEG Overflow Flag Tests
    // ========================================

    public function testNegOverflowFlagMostNegative32(): void
    {
        // NEG 0x80000000 should set OF (most negative 32-bit value)
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX

        $this->assertTrue($this->getOverflowFlag());
        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
    }

    public function testNegOverflowFlagMostNegative8(): void
    {
        // NEG 0x80 should set OF (most negative 8-bit value)
        $this->setRegister(RegisterType::EAX, 0x00000080);
        $this->executeBytes([0xF6, 0xD8]); // NEG AL

        $this->assertTrue($this->getOverflowFlag());
        $this->assertSame(0x80, $this->getRegister(RegisterType::EAX, 8));
    }

    public function testNegNoOverflowFlagNormal(): void
    {
        // NEG of normal value should not set OF
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX

        $this->assertFalse($this->getOverflowFlag());
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    public function testNegNoOverflowFlagZero(): void
    {
        // NEG 0 should not set OF
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX

        $this->assertFalse($this->getOverflowFlag());
        $this->assertFalse($this->getCarryFlag()); // CF=0 when operand is 0
    }

    public function testNegSetsZeroFlag(): void
    {
        // NEG 0 should set ZF
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX

        $this->assertTrue($this->getZeroFlag());
    }

    public function testNegSetsSignFlag(): void
    {
        // NEG of positive should produce negative (SF=1)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX

        $this->assertTrue($this->getSignFlag());
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // TEST Overflow Flag Tests
    // ========================================

    public function testTestClearsOverflowFlag(): void
    {
        // First set OF via a NEG that overflows
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX
        $this->assertTrue($this->getOverflowFlag()); // Verify OF is set

        // Now TEST should clear it
        $this->executeBytes([0xF7, 0xC0, 0xFF, 0xFF, 0xFF, 0xFF]); // TEST EAX, 0xFFFFFFFF
        $this->assertFalse($this->getOverflowFlag());
    }

    public function testTestClearsCarryFlag(): void
    {
        // TEST should always clear CF
        $this->setCarryFlag(true);
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0xF7, 0xC0, 0xFF, 0xFF, 0xFF, 0xFF]); // TEST EAX, 0xFFFFFFFF

        $this->assertFalse($this->getCarryFlag());
    }

    // ========================================
    // MUL Tests - digit 4
    // ========================================

    public function testMulReg8(): void
    {
        // MUL CL (0xF6 /4, ModRM=0xE1)
        // AL * CL => AX
        // AL = 10, CL = 20, result = 200 (0xC8)
        $this->setRegister(RegisterType::EAX, 0x0000000A); // AL = 10
        $this->setRegister(RegisterType::ECX, 0x00000014); // CL = 20
        $this->executeBytes([0xF6, 0xE1]); // MUL CL

        $this->assertSame(0xC8, $this->getRegister(RegisterType::EAX, 16)); // AX = 200
        $this->assertFalse($this->getCarryFlag()); // High byte is 0
    }

    public function testMulReg8WithHighByte(): void
    {
        // MUL CL with result > 255
        // AL = 16, CL = 16, result = 256 (0x100)
        $this->setRegister(RegisterType::EAX, 0x00000010); // AL = 16
        $this->setRegister(RegisterType::ECX, 0x00000010); // CL = 16
        $this->executeBytes([0xF6, 0xE1]); // MUL CL

        $this->assertSame(0x0100, $this->getRegister(RegisterType::EAX, 16)); // AX = 256
        $this->assertTrue($this->getCarryFlag()); // High byte is non-zero
    }

    public function testMulReg32(): void
    {
        // MUL ECX (0xF7 /4, ModRM=0xE1)
        // EAX * ECX => EDX:EAX
        // EAX = 10, ECX = 20, result = 200 (fits in EAX only)
        $this->setRegister(RegisterType::EAX, 0x0000000A); // 10
        $this->setRegister(RegisterType::ECX, 0x00000014); // 20
        $this->setRegister(RegisterType::EDX, 0xDEADBEEF); // Should be overwritten
        $this->executeBytes([0xF7, 0xE1]); // MUL ECX

        $this->assertSame(200, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0, $this->getRegister(RegisterType::EDX)); // High 32 bits = 0
        $this->assertFalse($this->getCarryFlag());
    }

    public function testMulReg32Large(): void
    {
        // MUL ECX with result > 32 bits
        // EAX = 0x10000000, ECX = 0x10, result = 0x100000000
        $this->setRegister(RegisterType::EAX, 0x10000000);
        $this->setRegister(RegisterType::ECX, 0x10);
        $this->executeBytes([0xF7, 0xE1]); // MUL ECX

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EDX));
        $this->assertTrue($this->getCarryFlag()); // EDX is non-zero
    }

    public function testMulByZero(): void
    {
        // MUL by 0 should produce 0
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->executeBytes([0xF7, 0xE1]); // MUL ECX

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EDX));
        $this->assertFalse($this->getCarryFlag());
    }

    // ========================================
    // IMUL Tests - digit 5
    // ========================================

    public function testImulReg8Positive(): void
    {
        // IMUL CL (0xF6 /5, ModRM=0xE9)
        // AL(signed) * CL(signed) => AX(signed)
        // AL = 10, CL = 5, result = 50
        $this->setRegister(RegisterType::EAX, 0x0000000A); // AL = 10
        $this->setRegister(RegisterType::ECX, 0x00000005); // CL = 5
        $this->executeBytes([0xF6, 0xE9]); // IMUL CL

        $this->assertSame(50, $this->getRegister(RegisterType::EAX, 16));
        $this->assertFalse($this->getCarryFlag()); // Result fits in AL
    }

    public function testImulReg8NegativeResult(): void
    {
        // IMUL CL with negative result
        // AL = -10 (0xF6), CL = 5, result = -50 (0xFFCE as 16-bit)
        $this->setRegister(RegisterType::EAX, 0x000000F6); // AL = -10 as signed byte
        $this->setRegister(RegisterType::ECX, 0x00000005); // CL = 5
        $this->executeBytes([0xF6, 0xE9]); // IMUL CL

        $ax = $this->getRegister(RegisterType::EAX, 16);
        $this->assertSame(0xFFCE, $ax); // -50 as unsigned 16-bit
        // -50 fits in signed 8-bit range (-128 to 127), so CF=0
        $this->assertFalse($this->getCarryFlag());
    }

    public function testImulReg32(): void
    {
        // IMUL ECX (0xF7 /5, ModRM=0xE9)
        // EAX(signed) * ECX(signed) => EDX:EAX(signed)
        $this->setRegister(RegisterType::EAX, 0x00000064); // 100
        $this->setRegister(RegisterType::ECX, 0x00000064); // 100
        $this->executeBytes([0xF7, 0xE9]); // IMUL ECX

        $this->assertSame(10000, $this->getRegister(RegisterType::EAX)); // 100 * 100 = 10000
        $this->assertSame(0, $this->getRegister(RegisterType::EDX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testImulReg32Negative(): void
    {
        // IMUL with negative operand
        // EAX = -1 (0xFFFFFFFF), ECX = 100, result = -100 (0xFFFFFF9C)
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF); // -1
        $this->setRegister(RegisterType::ECX, 0x00000064); // 100
        $this->executeBytes([0xF7, 0xE9]); // IMUL ECX

        $this->assertSame(0xFFFFFF9C, $this->getRegister(RegisterType::EAX)); // -100
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EDX)); // Sign extension
    }

    // ========================================
    // DIV Tests - digit 6
    // ========================================

    public function testDivReg8(): void
    {
        // DIV CL (0xF6 /6, ModRM=0xF1)
        // AX / CL => AL (quotient), AH (remainder)
        // AX = 100, CL = 7, quotient = 14, remainder = 2
        $this->setRegister(RegisterType::EAX, 0x00000064); // AX = 100
        $this->setRegister(RegisterType::ECX, 0x00000007); // CL = 7
        $this->executeBytes([0xF6, 0xF1]); // DIV CL

        // AL = quotient = 14 (0x0E)
        // AH = remainder = 2
        $ax = $this->getRegister(RegisterType::EAX, 16);
        $al = $ax & 0xFF;
        $ah = ($ax >> 8) & 0xFF;
        $this->assertSame(14, $al); // quotient
        $this->assertSame(2, $ah);  // remainder
    }

    public function testDivReg32(): void
    {
        // DIV ECX (0xF7 /6, ModRM=0xF1)
        // EDX:EAX / ECX => EAX (quotient), EDX (remainder)
        // EDX:EAX = 100, ECX = 7, quotient = 14, remainder = 2
        $this->setRegister(RegisterType::EAX, 0x00000064); // 100
        $this->setRegister(RegisterType::EDX, 0x00000000);
        $this->setRegister(RegisterType::ECX, 0x00000007); // 7
        $this->executeBytes([0xF7, 0xF1]); // DIV ECX

        $this->assertSame(14, $this->getRegister(RegisterType::EAX)); // quotient
        $this->assertSame(2, $this->getRegister(RegisterType::EDX));  // remainder
    }

    public function testDivReg32Large(): void
    {
        // DIV with 64-bit dividend
        // EDX:EAX = 0x100000000 (2^32), ECX = 2, quotient = 2^31
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setRegister(RegisterType::EDX, 0x00000001); // EDX:EAX = 0x100000000
        $this->setRegister(RegisterType::ECX, 0x00000002);
        $this->executeBytes([0xF7, 0xF1]); // DIV ECX

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX)); // quotient = 2^31
        $this->assertSame(0, $this->getRegister(RegisterType::EDX)); // remainder = 0
    }

    // ========================================
    // IDIV Tests - digit 7
    // ========================================

    public function testIdivReg8Positive(): void
    {
        // IDIV CL (0xF6 /7, ModRM=0xF9)
        // AX(signed) / CL(signed) => AL (quotient), AH (remainder)
        // AX = 100, CL = 7, quotient = 14, remainder = 2
        $this->setRegister(RegisterType::EAX, 0x00000064); // AX = 100
        $this->setRegister(RegisterType::ECX, 0x00000007); // CL = 7
        $this->executeBytes([0xF6, 0xF9]); // IDIV CL

        $ax = $this->getRegister(RegisterType::EAX, 16);
        $al = $ax & 0xFF;
        $ah = ($ax >> 8) & 0xFF;
        $this->assertSame(14, $al); // quotient
        $this->assertSame(2, $ah);  // remainder
    }

    public function testIdivReg8Negative(): void
    {
        // IDIV CL with negative dividend
        // AX = -100 (0xFF9C), CL = 7, quotient = -14 (0xF2), remainder = -2 (0xFE)
        $this->setRegister(RegisterType::EAX, 0x0000FF9C); // AX = -100 as signed 16-bit
        $this->setRegister(RegisterType::ECX, 0x00000007); // CL = 7
        $this->executeBytes([0xF6, 0xF9]); // IDIV CL

        $ax = $this->getRegister(RegisterType::EAX, 16);
        $al = $ax & 0xFF;
        $ah = ($ax >> 8) & 0xFF;
        $this->assertSame(0xF2, $al); // -14 as unsigned byte
        $this->assertSame(0xFE, $ah); // -2 as unsigned byte
    }

    public function testIdivReg32(): void
    {
        // IDIV ECX (0xF7 /7, ModRM=0xF9)
        // EDX:EAX(signed) / ECX(signed) => EAX (quotient), EDX (remainder)
        $this->setRegister(RegisterType::EAX, 0x00000064); // 100
        $this->setRegister(RegisterType::EDX, 0x00000000);
        $this->setRegister(RegisterType::ECX, 0x00000007); // 7
        $this->executeBytes([0xF7, 0xF9]); // IDIV ECX

        $this->assertSame(14, $this->getRegister(RegisterType::EAX)); // quotient
        $this->assertSame(2, $this->getRegister(RegisterType::EDX));  // remainder
    }

    public function testIdivReg32Negative(): void
    {
        // IDIV with negative dividend
        // EDX:EAX = -100 (0xFFFFFFFFFFFFFF9C), ECX = 7
        // quotient = -14, remainder = -2
        $this->setRegister(RegisterType::EAX, 0xFFFFFF9C); // -100
        $this->setRegister(RegisterType::EDX, 0xFFFFFFFF); // Sign extension
        $this->setRegister(RegisterType::ECX, 0x00000007);
        $this->executeBytes([0xF7, 0xF9]); // IDIV ECX

        $this->assertSame(0xFFFFFFF2, $this->getRegister(RegisterType::EAX)); // -14
        $this->assertSame(0xFFFFFFFE, $this->getRegister(RegisterType::EDX)); // -2
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testNotTwiceRestoresOriginal(): void
    {
        // NOT twice should restore original value
        $original = 0x12345678;
        $this->setRegister(RegisterType::EAX, $original);
        $this->executeBytes([0xF7, 0xD0]); // NOT EAX
        $this->executeBytes([0xF7, 0xD0]); // NOT EAX

        $this->assertSame($original, $this->getRegister(RegisterType::EAX));
    }

    public function testNegTwiceRestoresOriginal(): void
    {
        // NEG twice should restore original value
        $original = 0x12345678;
        $this->setRegister(RegisterType::EAX, $original);
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX
        $this->executeBytes([0xF7, 0xD8]); // NEG EAX

        $this->assertSame($original, $this->getRegister(RegisterType::EAX));
    }

    public function testMulByOne(): void
    {
        // MUL by 1 should not change value (for values that fit in EAX)
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::ECX, 0x00000001);
        $this->executeBytes([0xF7, 0xE1]); // MUL ECX

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0, $this->getRegister(RegisterType::EDX));
    }

    public function testDivByOne(): void
    {
        // DIV by 1 should not change value (quotient = dividend, remainder = 0)
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EDX, 0x00000000);
        $this->setRegister(RegisterType::ECX, 0x00000001);
        $this->executeBytes([0xF7, 0xF1]); // DIV ECX

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX)); // quotient
        $this->assertSame(0, $this->getRegister(RegisterType::EDX)); // remainder
    }

    public function testDifferentRegistersNot(): void
    {
        // Test NOT on different registers
        // ModRM for NOT reg (0xF7 /2) = 11 010 rrr = 0xD0 + reg
        $testCases = [
            [RegisterType::EAX, 0xD0],
            [RegisterType::ECX, 0xD1],
            [RegisterType::EDX, 0xD2],
            [RegisterType::EBX, 0xD3],
        ];

        foreach ($testCases as [$reg, $modrm]) {
            $this->setUp();
            $this->setRegister($reg, 0x0F0F0F0F);
            $this->executeBytes([0xF7, $modrm]); // NOT reg

            $this->assertSame(
                0xF0F0F0F0,
                $this->getRegister($reg),
                sprintf('NOT failed for register %s (ModRM=0x%02X)', $reg->name, $modrm)
            );
        }
    }
}
