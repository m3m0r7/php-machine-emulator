<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Group2;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for Group2 instructions: shift and rotate operations
 * Opcodes: 0xC0, 0xC1, 0xD0, 0xD1, 0xD2, 0xD3
 * Operations: ROL, ROR, RCL, RCR, SHL, SHR, SAR
 */
class Group2Test extends InstructionTestCase
{
    private Group2 $group2;

    protected function setUp(): void
    {
        parent::setUp();
        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->group2 = new Group2($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        if (in_array($opcode, [0xC0, 0xC1, 0xD0, 0xD1, 0xD2, 0xD3], true)) {
            return $this->group2;
        }
        return null;
    }

    // ========================================
    // SHL (Shift Left) Tests - digit 4
    // ========================================

    public function testShlReg32By1(): void
    {
        // SHL EAX, 1 (0xD1 /4, ModRM=0xE0)
        // EAX = 0x00000001, after SHL by 1 => 0x00000002
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0xD1, 0xE0]); // SHL EAX, 1

        $this->assertSame(0x00000002, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag()); // bit 31 was 0
    }

    public function testShlReg32By1WithCarry(): void
    {
        // SHL EAX, 1 with CF set when MSB is 1
        // EAX = 0x80000000, after SHL by 1 => 0x00000000, CF=1
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xD1, 0xE0]); // SHL EAX, 1

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag()); // bit 31 was 1
    }

    public function testShlReg32ByImm8(): void
    {
        // SHL EAX, 4 (0xC1 /4, ModRM=0xE0, imm8=4)
        // EAX = 0x12345678, after SHL by 4 => 0x23456780
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0xC1, 0xE0, 0x04]); // SHL EAX, 4

        $this->assertSame(0x23456780, $this->getRegister(RegisterType::EAX));
        // CF = bit 28 of original value (0x1 from 0x12345678)
        $this->assertTrue($this->getCarryFlag());
    }

    public function testShlReg32ByCl(): void
    {
        // SHL EAX, CL (0xD3 /4, ModRM=0xE0)
        // EAX = 0x00000001, CL = 5, after SHL by 5 => 0x00000020
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setRegister(RegisterType::ECX, 0x05);
        $this->executeBytes([0xD3, 0xE0]); // SHL EAX, CL

        $this->assertSame(0x00000020, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testShlReg8By1(): void
    {
        // SHL AL, 1 (0xD0 /4, ModRM=0xE0)
        // AL = 0x40, after SHL by 1 => 0x80
        $this->setRegister(RegisterType::EAX, 0x00000040);
        $this->executeBytes([0xD0, 0xE0]); // SHL AL, 1

        $this->assertSame(0x80, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag()); // bit 7 was 0
    }

    public function testShlReg8By1WithCarry(): void
    {
        // SHL AL, 1 with MSB set
        // AL = 0x80, after SHL by 1 => 0x00, CF=1
        $this->setRegister(RegisterType::EAX, 0x00000080);
        $this->executeBytes([0xD0, 0xE0]); // SHL AL, 1

        $this->assertSame(0x00, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getCarryFlag());
    }

    public function testShlByZeroDoesNotChangeValue(): void
    {
        // SHL EAX, 0 should not change the value
        $this->setRegister(RegisterType::EAX, 0xDEADBEEF);
        $this->setRegister(RegisterType::ECX, 0x00);
        $this->executeBytes([0xD3, 0xE0]); // SHL EAX, CL (CL=0)

        $this->assertSame(0xDEADBEEF, $this->getRegister(RegisterType::EAX));
    }

    public function testShlShiftCountMaskedTo5Bits(): void
    {
        // Shift count is masked to 5 bits (0-31)
        // CL = 0x21 (33) => effective shift = 1
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setRegister(RegisterType::ECX, 0x21); // 33 & 0x1F = 1
        $this->executeBytes([0xD3, 0xE0]); // SHL EAX, CL

        $this->assertSame(0x00000002, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // SHR (Shift Right Logical) Tests - digit 5
    // ========================================

    public function testShrReg32By1(): void
    {
        // SHR EAX, 1 (0xD1 /5, ModRM=0xE8)
        // EAX = 0x00000002, after SHR by 1 => 0x00000001
        $this->setRegister(RegisterType::EAX, 0x00000002);
        $this->executeBytes([0xD1, 0xE8]); // SHR EAX, 1

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testShrReg32By1WithCarry(): void
    {
        // SHR EAX, 1 with CF set when LSB is 1
        // EAX = 0x00000001, after SHR by 1 => 0x00000000, CF=1
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0xD1, 0xE8]); // SHR EAX, 1

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag());
    }

    public function testShrReg32ByImm8(): void
    {
        // SHR EAX, 4 (0xC1 /5, ModRM=0xE8, imm8=4)
        // EAX = 0x12345678, after SHR by 4 => 0x01234567
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0xC1, 0xE8, 0x04]); // SHR EAX, 4

        $this->assertSame(0x01234567, $this->getRegister(RegisterType::EAX));
        // CF = bit 3 of original (0x1000 & 0x8 = 0x8 from 0x12345678 >> 3 & 1 = 1)
        $this->assertTrue($this->getCarryFlag());
    }

    public function testShrMsbClearedForPositiveResult(): void
    {
        // SHR should always produce positive result (MSB cleared)
        // EAX = 0x80000000, after SHR by 1 => 0x40000000
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xD1, 0xE8]); // SHR EAX, 1

        $this->assertSame(0x40000000, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testShrReg8By1(): void
    {
        // SHR AL, 1 (0xD0 /5, ModRM=0xE8)
        // AL = 0x80, after SHR by 1 => 0x40
        $this->setRegister(RegisterType::EAX, 0x00000080);
        $this->executeBytes([0xD0, 0xE8]); // SHR AL, 1

        $this->assertSame(0x40, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag());
    }

    // ========================================
    // SAR (Shift Right Arithmetic) Tests - digit 7
    // ========================================

    public function testSarReg32By1Positive(): void
    {
        // SAR EAX, 1 (0xD1 /7, ModRM=0xF8)
        // EAX = 0x00000002, after SAR by 1 => 0x00000001 (MSB preserved as 0)
        $this->setRegister(RegisterType::EAX, 0x00000002);
        $this->executeBytes([0xD1, 0xF8]); // SAR EAX, 1

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testSarReg32By1Negative(): void
    {
        // SAR EAX, 1 with negative number (sign extension)
        // EAX = 0x80000000, after SAR by 1 => 0xC0000000 (MSB propagated)
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xD1, 0xF8]); // SAR EAX, 1

        $this->assertSame(0xC0000000, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testSarReg32ByImm8Negative(): void
    {
        // SAR EAX, 4 with negative number
        // EAX = 0xFFFFFFFF (-1), after SAR by 4 => 0xFFFFFFFF (-1)
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0xC1, 0xF8, 0x04]); // SAR EAX, 4

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag()); // bit 3 was 1
    }

    public function testSarReg8SignExtension(): void
    {
        // SAR AL, 1 with negative number
        // AL = 0x80 (-128), after SAR by 1 => 0xC0 (-64)
        $this->setRegister(RegisterType::EAX, 0x00000080);
        $this->executeBytes([0xD0, 0xF8]); // SAR AL, 1

        $this->assertSame(0xC0, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testSarReg8MultipleShifts(): void
    {
        // SAR AL, 4 with negative number
        // AL = 0x80 (-128), after SAR by 4 => 0xF8 (-8)
        $this->setRegister(RegisterType::EAX, 0x00000080);
        $this->executeBytes([0xC0, 0xF8, 0x04]); // SAR AL, 4

        $this->assertSame(0xF8, $this->getRegister(RegisterType::EAX, 8));
    }

    // ========================================
    // ROL (Rotate Left) Tests - digit 0
    // ========================================

    public function testRolReg32By1(): void
    {
        // ROL EAX, 1 (0xD1 /0, ModRM=0xC0)
        // EAX = 0x80000001, after ROL by 1 => 0x00000003
        $this->setRegister(RegisterType::EAX, 0x80000001);
        $this->executeBytes([0xD1, 0xC0]); // ROL EAX, 1

        $this->assertSame(0x00000003, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag()); // CF = LSB after rotate
    }

    public function testRolReg32ByImm8(): void
    {
        // ROL EAX, 4 (0xC1 /0, ModRM=0xC0, imm8=4)
        // EAX = 0x12345678, after ROL by 4 => 0x23456781
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0xC1, 0xC0, 0x04]); // ROL EAX, 4

        $this->assertSame(0x23456781, $this->getRegister(RegisterType::EAX));
    }

    public function testRolReg8By1(): void
    {
        // ROL AL, 1 (0xD0 /0, ModRM=0xC0)
        // AL = 0x81, after ROL by 1 => 0x03
        $this->setRegister(RegisterType::EAX, 0x00000081);
        $this->executeBytes([0xD0, 0xC0]); // ROL AL, 1

        $this->assertSame(0x03, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getCarryFlag());
    }

    public function testRolFullRotation(): void
    {
        // ROL EAX, 32 should return to original value
        // Note: shift count is masked to 5 bits, so 32 & 0x1F = 0
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::ECX, 0x20); // 32 & 0x1F = 0
        $this->executeBytes([0xD3, 0xC0]); // ROL EAX, CL

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // ROR (Rotate Right) Tests - digit 1
    // ========================================

    public function testRorReg32By1(): void
    {
        // ROR EAX, 1 (0xD1 /1, ModRM=0xC8)
        // EAX = 0x00000003, after ROR by 1 => 0x80000001
        $this->setRegister(RegisterType::EAX, 0x00000003);
        $this->executeBytes([0xD1, 0xC8]); // ROR EAX, 1

        $this->assertSame(0x80000001, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag()); // CF = MSB after rotate
    }

    public function testRorReg32ByImm8(): void
    {
        // ROR EAX, 4 (0xC1 /1, ModRM=0xC8, imm8=4)
        // EAX = 0x12345678, after ROR by 4 => 0x81234567
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0xC1, 0xC8, 0x04]); // ROR EAX, 4

        $this->assertSame(0x81234567, $this->getRegister(RegisterType::EAX));
    }

    public function testRorReg8By1(): void
    {
        // ROR AL, 1 (0xD0 /1, ModRM=0xC8)
        // AL = 0x03, after ROR by 1 => 0x81
        $this->setRegister(RegisterType::EAX, 0x00000003);
        $this->executeBytes([0xD0, 0xC8]); // ROR AL, 1

        $this->assertSame(0x81, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // RCL (Rotate through Carry Left) Tests - digit 2
    // ========================================

    public function testRclReg32By1WithCarryClear(): void
    {
        // RCL EAX, 1 with CF=0 (0xD1 /2, ModRM=0xD0)
        // EAX = 0x80000000, CF=0, after RCL by 1 => 0x00000000, CF=1
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->setCarryFlag(false);
        $this->executeBytes([0xD1, 0xD0]); // RCL EAX, 1

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag());
    }

    public function testRclReg32By1WithCarrySet(): void
    {
        // RCL EAX, 1 with CF=1 (0xD1 /2, ModRM=0xD0)
        // EAX = 0x00000000, CF=1, after RCL by 1 => 0x00000001, CF=0
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setCarryFlag(true);
        $this->executeBytes([0xD1, 0xD0]); // RCL EAX, 1

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testRclReg8By1(): void
    {
        // RCL AL, 1 with CF=1 (0xD0 /2, ModRM=0xD0)
        // AL = 0x80, CF=1, after RCL by 1 => 0x01, CF=1
        $this->setRegister(RegisterType::EAX, 0x00000080);
        $this->setCarryFlag(true);
        $this->executeBytes([0xD0, 0xD0]); // RCL AL, 1

        $this->assertSame(0x01, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // RCR (Rotate through Carry Right) Tests - digit 3
    // ========================================

    public function testRcrReg32By1WithCarryClear(): void
    {
        // RCR EAX, 1 with CF=0 (0xD1 /3, ModRM=0xD8)
        // EAX = 0x00000001, CF=0, after RCR by 1 => 0x00000000, CF=1
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setCarryFlag(false);
        $this->executeBytes([0xD1, 0xD8]); // RCR EAX, 1

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag());
    }

    public function testRcrReg32By1WithCarrySet(): void
    {
        // RCR EAX, 1 with CF=1 (0xD1 /3, ModRM=0xD8)
        // EAX = 0x00000000, CF=1, after RCR by 1 => 0x80000000, CF=0
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setCarryFlag(true);
        $this->executeBytes([0xD1, 0xD8]); // RCR EAX, 1

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testRcrReg8By1(): void
    {
        // RCR AL, 1 with CF=1 (0xD0 /3, ModRM=0xD8)
        // AL = 0x01, CF=1, after RCR by 1 => 0x80, CF=1
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setCarryFlag(true);
        $this->executeBytes([0xD0, 0xD8]); // RCR AL, 1

        $this->assertSame(0x80, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // Edge Cases and Special Scenarios
    // ========================================

    public function testShlZeroValue(): void
    {
        // SHL 0, n should always produce 0
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0xC1, 0xE0, 0x10]); // SHL EAX, 16

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
    }

    public function testShrAllOnes(): void
    {
        // SHR 0xFFFFFFFF, 31 should produce 1
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0xC1, 0xE8, 0x1F]); // SHR EAX, 31

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag()); // bit 30 was 1
    }

    public function testSarAllOnes(): void
    {
        // SAR 0xFFFFFFFF, 31 should still be 0xFFFFFFFF (sign extended)
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0xC1, 0xF8, 0x1F]); // SAR EAX, 31

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    public function testRolRorInverse(): void
    {
        // ROL by n followed by ROR by n should restore original value
        $original = 0x12345678;
        $this->setRegister(RegisterType::EAX, $original);
        $this->executeBytes([0xC1, 0xC0, 0x07]); // ROL EAX, 7

        $rotated = $this->getRegister(RegisterType::EAX);
        $this->assertNotSame($original, $rotated);

        // Reset for ROR test
        $this->setRegister(RegisterType::EAX, $rotated);
        // For ROR test, we need to re-read the memory stream
        $this->executeBytes([0xC1, 0xC8, 0x07]); // ROR EAX, 7

        $this->assertSame($original, $this->getRegister(RegisterType::EAX));
    }

    public function testShlCarryFlagCorrectBitPosition(): void
    {
        // For SHL by n, CF = bit (size - n) of original value
        // SHL by 1: CF = bit 31
        // SHL by 4: CF = bit 28
        // SHL by 31: CF = bit 0

        // Test SHL by 31: value 0x00000001 shifted left by 31 = 0x80000000
        // CF = original bit (32 - 31) = bit 1 of original value = 0
        $this->setRegister(RegisterType::EAX, 0x00000001); // bit 0 = 1, bit 1 = 0
        $this->executeBytes([0xC1, 0xE0, 0x1F]); // SHL EAX, 31

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        // CF is the bit that was shifted out, which is bit 1 of original value
        $this->assertFalse($this->getCarryFlag()); // original bit 1 was 0

        // Test with bit 1 set
        $this->setUp();
        $this->setRegister(RegisterType::EAX, 0x00000003); // bit 0 = 1, bit 1 = 1
        $this->executeBytes([0xC1, 0xE0, 0x1F]); // SHL EAX, 31

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag()); // original bit 1 was 1
    }

    public function testShrCarryFlagCorrectBitPosition(): void
    {
        // For SHR by n, CF = bit (n-1) of original value
        // SHR by 1: CF = bit 0
        // SHR by 4: CF = bit 3

        // Test SHR by 4
        $this->setRegister(RegisterType::EAX, 0x00000008); // bit 3 = 1
        $this->executeBytes([0xC1, 0xE8, 0x04]); // SHR EAX, 4

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag()); // original bit 3 is CF
    }

    public function testDifferentRegisters(): void
    {
        // Test SHL on different registers to ensure ModRM decoding is correct
        // ModRM for SHL reg, 1 (0xD1 /4) with different registers:
        // /4 means digit=4 (SHL), reg encoding in r/m field
        // EAX=0, ECX=1, EDX=2, EBX=3
        // ModRM = 11 100 rrr = 0xE0 + reg
        $testCases = [
            [RegisterType::EAX, 0xE0], // 11 100 000
            [RegisterType::ECX, 0xE1], // 11 100 001
            [RegisterType::EDX, 0xE2], // 11 100 010
            [RegisterType::EBX, 0xE3], // 11 100 011
        ];

        foreach ($testCases as [$reg, $modrm]) {
            // Reset to avoid interference
            $this->setUp();
            $this->setRegister($reg, 0x00000001);
            $this->executeBytes([0xD1, $modrm]); // SHL reg, 1

            $this->assertSame(
                0x00000002,
                $this->getRegister($reg),
                sprintf('SHL failed for register %s (ModRM=0x%02X)', $reg->name, $modrm)
            );
        }
    }

    /**
     * Test SHL with values that could cause overflow issues in PHP
     */
    public function testShlLargeValuesNoOverflow(): void
    {
        // This is a regression test for potential PHP integer overflow issues
        $this->setRegister(RegisterType::EAX, 0x7FFFFFFF);
        $this->executeBytes([0xD1, 0xE0]); // SHL EAX, 1

        $this->assertSame(0xFFFFFFFE, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag()); // bit 31 was 0
    }

    /**
     * Test that flags are updated correctly
     */
    public function testShlZeroFlagSet(): void
    {
        // SHL should set ZF when result is 0
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0xC1, 0xE0, 0x20]); // SHL EAX, 32 (masked to 0, no change!)

        // Actually, shift by 0 doesn't change value
        // Let's use a real case that produces 0
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xD1, 0xE0]); // SHL EAX, 1

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getZeroFlag());
    }

    public function testShlSignFlagSet(): void
    {
        // SHL should set SF when result has MSB set
        $this->setRegister(RegisterType::EAX, 0x40000000);
        $this->executeBytes([0xD1, 0xE0]); // SHL EAX, 1

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getSignFlag());
    }

    // ========================================
    // Overflow Flag (OF) Tests for count=1
    // ========================================

    public function testShlOverflowFlagBy1MsbChanged(): void
    {
        // SHL by 1: OF = MSB changed (result MSB XOR CF)
        // 0x40000000 << 1 = 0x80000000, CF = 0
        // MSB changed from 0 to 1, OF = 1
        $this->setRegister(RegisterType::EAX, 0x40000000);
        $this->executeBytes([0xD1, 0xE0]); // SHL EAX, 1

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag()); // MSB changed from 0 to 1
    }

    public function testShlNoOverflowFlagBy1MsbUnchanged(): void
    {
        // SHL by 1: OF = 0 when MSB doesn't change
        // 0x00000001 << 1 = 0x00000002, CF = 0
        // MSB unchanged (0 -> 0), OF = 0
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0xD1, 0xE0]); // SHL EAX, 1

        $this->assertSame(0x00000002, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
    }

    public function testShlOverflowFlagBy1CfSet(): void
    {
        // SHL by 1: OF = MSB XOR CF (result MSB differs from CF)
        // 0x80000000 << 1 = 0x00000000, CF = 1
        // Result MSB = 0, CF = 1, they differ
        // But wait - Intel spec says OF is set when sign changes
        // 0x80000000 (negative) -> 0x00000000 (zero/positive)
        // Sign changed, but result is 0, MSB=0, CF=1, 0 XOR 1 = 1
        // Actually the implementation should match: MSB(0) != CF(1) -> OF = true
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xD1, 0xE0]); // SHL EAX, 1

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag());
        // Let's verify what Intel spec actually says - check the actual flag
        // Result MSB = 0, CF = 1 -> 0 XOR 1 = 1, so OF should be TRUE
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testShlNoOverflowFlagBy1BothSet(): void
    {
        // SHL by 1: OF = MSB XOR CF
        // 0xC0000000 << 1 = 0x80000000, CF = 1
        // Result MSB = 1, CF = 1, same -> OF = 0
        $this->setRegister(RegisterType::EAX, 0xC0000000);
        $this->executeBytes([0xD1, 0xE0]); // SHL EAX, 1

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getCarryFlag());
        // MSB = 1, CF = 1 -> 1 XOR 1 = 0, OF should be FALSE
        $this->assertFalse($this->getOverflowFlag());
    }

    public function testShrOverflowFlagBy1OriginalMsbSet(): void
    {
        // SHR by 1: OF = original MSB
        // 0x80000000 >> 1 = 0x40000000
        // Original MSB was 1, OF = 1
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xD1, 0xE8]); // SHR EAX, 1

        $this->assertSame(0x40000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testShrNoOverflowFlagBy1OriginalMsbClear(): void
    {
        // SHR by 1: OF = original MSB
        // 0x7FFFFFFF >> 1 = 0x3FFFFFFF
        // Original MSB was 0, OF = 0
        $this->setRegister(RegisterType::EAX, 0x7FFFFFFF);
        $this->executeBytes([0xD1, 0xE8]); // SHR EAX, 1

        $this->assertSame(0x3FFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getOverflowFlag());
    }

    public function testSarOverflowFlagBy1AlwaysClear(): void
    {
        // SAR by 1: OF = 0 (sign is always preserved)
        // 0x80000000 >> 1 (arithmetic) = 0xC0000000
        // Sign preserved, OF = 0
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0xD1, 0xF8]); // SAR EAX, 1

        $this->assertSame(0xC0000000, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getOverflowFlag());
    }

    public function testSarOverflowFlagBy1PositiveValue(): void
    {
        // SAR by 1: OF = 0 regardless of value
        $this->setRegister(RegisterType::EAX, 0x7FFFFFFF);
        $this->executeBytes([0xD1, 0xF8]); // SAR EAX, 1

        $this->assertSame(0x3FFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getOverflowFlag());
    }

    public function testShlOverflowFlag8BitBy1(): void
    {
        // 8-bit SHL by 1: OF = MSB XOR CF
        // 0x40 << 1 = 0x80, CF = 0
        // MSB changed from 0 to 1, OF = 1
        $this->setRegister(RegisterType::EAX, 0x00000040);
        $this->executeBytes([0xD0, 0xE0]); // SHL AL, 1

        $this->assertSame(0x80, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testShrOverflowFlag8BitBy1(): void
    {
        // 8-bit SHR by 1: OF = original MSB
        // 0x80 >> 1 = 0x40
        // Original MSB was 1, OF = 1
        $this->setRegister(RegisterType::EAX, 0x00000080);
        $this->executeBytes([0xD0, 0xE8]); // SHR AL, 1

        $this->assertSame(0x40, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testSarOverflowFlag8BitBy1(): void
    {
        // 8-bit SAR by 1: OF = 0
        // 0x80 >> 1 (arithmetic) = 0xC0
        // OF is always 0 for SAR by 1
        $this->setRegister(RegisterType::EAX, 0x00000080);
        $this->executeBytes([0xD0, 0xF8]); // SAR AL, 1

        $this->assertSame(0xC0, $this->getRegister(RegisterType::EAX, 8));
        $this->assertFalse($this->getOverflowFlag());
    }
}
