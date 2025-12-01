<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\CmpRegRm;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for CMP r/m, r and CMP r, r/m instructions
 * Opcodes: 0x38, 0x39, 0x3A, 0x3B
 * 0x38: CMP r/m8, r8
 * 0x39: CMP r/m16/32, r16/32
 * 0x3A: CMP r8, r/m8
 * 0x3B: CMP r16/32, r/m16/32
 */
class CmpRegRmTest extends InstructionTestCase
{
    private CmpRegRm $cmpRegRm;

    protected function setUp(): void
    {
        parent::setUp();
        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->cmpRegRm = new CmpRegRm($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        if (in_array($opcode, [0x38, 0x39, 0x3A, 0x3B], true)) {
            return $this->cmpRegRm;
        }
        return null;
    }

    // ========================================
    // CMP r/m8, r8 (0x38) Tests
    // ========================================

    public function testCmpRm8R8Equal(): void
    {
        // CMP AL, CL (0x38 /r, ModRM=0xC8 = 11 001 000)
        // reg=CL(1), r/m=AL(0)
        $this->setRegister(RegisterType::EAX, 0x00000055); // AL = 0x55
        $this->setRegister(RegisterType::ECX, 0x00000055); // CL = 0x55
        $this->executeBytes([0x38, 0xC8]); // CMP AL, CL

        // Values should not change
        $this->assertSame(0x55, $this->getRegister(RegisterType::EAX, 8));
        $this->assertSame(0x55, $this->getRegister(RegisterType::ECX, 8));
        $this->assertTrue($this->getZeroFlag()); // Equal
        $this->assertFalse($this->getCarryFlag()); // No borrow
    }

    public function testCmpRm8R8Greater(): void
    {
        // CMP AL, CL where AL > CL
        $this->setRegister(RegisterType::EAX, 0x000000FF); // AL = 0xFF
        $this->setRegister(RegisterType::ECX, 0x00000010); // CL = 0x10
        $this->executeBytes([0x38, 0xC8]); // CMP AL, CL

        $this->assertFalse($this->getZeroFlag()); // Not equal
        $this->assertFalse($this->getCarryFlag()); // AL >= CL, no borrow
    }

    public function testCmpRm8R8Less(): void
    {
        // CMP AL, CL where AL < CL
        $this->setRegister(RegisterType::EAX, 0x00000010); // AL = 0x10
        $this->setRegister(RegisterType::ECX, 0x000000FF); // CL = 0xFF
        $this->executeBytes([0x38, 0xC8]); // CMP AL, CL

        $this->assertFalse($this->getZeroFlag()); // Not equal
        $this->assertTrue($this->getCarryFlag()); // AL < CL, borrow
    }

    // ========================================
    // CMP r/m32, r32 (0x39) Tests
    // ========================================

    public function testCmpRm32R32Equal(): void
    {
        // CMP EAX, ECX (0x39 /r, ModRM=0xC8)
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::ECX, 0x12345678);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
    }

    public function testCmpRm32R32Greater(): void
    {
        // CMP EAX, ECX where EAX > ECX
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->setRegister(RegisterType::ECX, 0x00000001);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag()); // EAX >= ECX
    }

    public function testCmpRm32R32Less(): void
    {
        // CMP EAX, ECX where EAX < ECX
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setRegister(RegisterType::ECX, 0xFFFFFFFF);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getCarryFlag()); // EAX < ECX, borrow
    }

    // ========================================
    // CMP r8, r/m8 (0x3A) Tests
    // ========================================

    public function testCmpR8Rm8Equal(): void
    {
        // CMP CL, AL (0x3A /r, ModRM=0xC8)
        // This is reversed: reg=CL is destination
        $this->setRegister(RegisterType::EAX, 0x00000055);
        $this->setRegister(RegisterType::ECX, 0x00000055);
        $this->executeBytes([0x3A, 0xC8]); // CMP CL, AL

        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
    }

    public function testCmpR8Rm8Less(): void
    {
        // CMP CL, AL where CL < AL
        $this->setRegister(RegisterType::EAX, 0x000000FF);
        $this->setRegister(RegisterType::ECX, 0x00000010);
        $this->executeBytes([0x3A, 0xC8]); // CMP CL, AL

        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getCarryFlag()); // CL < AL, borrow
    }

    // ========================================
    // CMP r32, r/m32 (0x3B) Tests
    // ========================================

    public function testCmpR32Rm32Equal(): void
    {
        // CMP ECX, EAX (0x3B /r, ModRM=0xC8)
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::ECX, 0x12345678);
        $this->executeBytes([0x3B, 0xC8]); // CMP ECX, EAX

        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
    }

    public function testCmpR32Rm32Greater(): void
    {
        // CMP ECX, EAX where ECX > EAX
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setRegister(RegisterType::ECX, 0xFFFFFFFF);
        $this->executeBytes([0x3B, 0xC8]); // CMP ECX, EAX

        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag()); // ECX >= EAX
    }

    public function testCmpR32Rm32Less(): void
    {
        // CMP ECX, EAX where ECX < EAX
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->setRegister(RegisterType::ECX, 0x00000001);
        $this->executeBytes([0x3B, 0xC8]); // CMP ECX, EAX

        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getCarryFlag()); // ECX < EAX, borrow
    }

    // ========================================
    // Sign Flag Tests
    // ========================================

    public function testCmpSetsSignFlagNegativeResult(): void
    {
        // CMP that produces negative result
        // 1 - 2 = -1 (0xFFFFFFFF)
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setRegister(RegisterType::ECX, 0x00000002);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertTrue($this->getSignFlag()); // Result is negative
        $this->assertTrue($this->getCarryFlag()); // Borrow occurred
    }

    public function testCmpClearsSignFlagPositiveResult(): void
    {
        // CMP that produces positive result
        // 2 - 1 = 1
        $this->setRegister(RegisterType::EAX, 0x00000002);
        $this->setRegister(RegisterType::ECX, 0x00000001);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertFalse($this->getSignFlag()); // Result is positive
        $this->assertFalse($this->getCarryFlag());
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testCmpZeroWithZero(): void
    {
        // CMP 0, 0
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getSignFlag());
    }

    public function testCmpMaxWithMax(): void
    {
        // CMP 0xFFFFFFFF, 0xFFFFFFFF
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->setRegister(RegisterType::ECX, 0xFFFFFFFF);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
    }

    public function testCmpDoesNotModifyOperands(): void
    {
        // CMP should not modify the operands
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::ECX, 0xDEADBEEF);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0xDEADBEEF, $this->getRegister(RegisterType::ECX));
    }

    public function testCmpDifferentRegisters(): void
    {
        // Test CMP on different register combinations
        $testCases = [
            // [reg1, reg2, modrm for CMP reg1, reg2 (0x39)]
            [RegisterType::EAX, RegisterType::EBX, 0xD8], // CMP EAX, EBX (r/m=0, reg=3)
            [RegisterType::ECX, RegisterType::EDX, 0xD1], // CMP ECX, EDX (r/m=1, reg=2)
            [RegisterType::EDX, RegisterType::EAX, 0xC2], // CMP EDX, EAX (r/m=2, reg=0)
        ];

        foreach ($testCases as [$reg1, $reg2, $modrm]) {
            $this->setUp();
            $this->setRegister($reg1, 0x00000100);
            $this->setRegister($reg2, 0x00000100);
            $this->executeBytes([0x39, $modrm]); // CMP reg1, reg2

            $this->assertTrue(
                $this->getZeroFlag(),
                sprintf('CMP failed for %s, %s (ModRM=0x%02X)', $reg1->name, $reg2->name, $modrm)
            );
        }
    }

    // ========================================
    // Boundary Value Tests (Important for LZMA)
    // ========================================

    public function testCmpBoundaryJustBelow(): void
    {
        // Common pattern in LZMA: compare bound with code
        // bound = 0x80000000, code = 0x7FFFFFFF
        $this->setRegister(RegisterType::EAX, 0x7FFFFFFF); // code
        $this->setRegister(RegisterType::ECX, 0x80000000); // bound
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX (code < bound?)

        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getCarryFlag()); // EAX < ECX (unsigned)
    }

    public function testCmpBoundaryJustAbove(): void
    {
        // bound = 0x80000000, code = 0x80000001
        $this->setRegister(RegisterType::EAX, 0x80000001); // code
        $this->setRegister(RegisterType::ECX, 0x80000000); // bound
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag()); // EAX >= ECX (unsigned)
    }

    public function testCmpBoundaryExactlyEqual(): void
    {
        // bound = code
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->setRegister(RegisterType::ECX, 0x80000000);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
    }

    public function testCmpZeroAgainstLargeValue(): void
    {
        // Important for LZMA when prob*range might be 0
        $this->setRegister(RegisterType::EAX, 0x00000000); // EAX = 0
        $this->setRegister(RegisterType::ECX, 0x12345678); // ECX = large value
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertTrue($this->getCarryFlag()); // 0 < large value
    }

    public function testCmpLargeValueAgainstZero(): void
    {
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->executeBytes([0x39, 0xC8]); // CMP EAX, ECX

        $this->assertFalse($this->getCarryFlag()); // large value >= 0
    }
}
