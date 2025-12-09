<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Movzx;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Movsx;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for MOVZX and MOVSX instructions.
 *
 * MOVZX r16/32, r/m8: 0x0F 0xB6
 * MOVZX r16/32, r/m16: 0x0F 0xB7
 * MOVSX r16/32, r/m8: 0x0F 0xBE
 * MOVSX r16/32, r/m16: 0x0F 0xBF
 */
class MovzxMovsxTest extends TwoByteOpTestCase
{
    private Movzx $movzx;
    private Movsx $movsx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->movzx = new Movzx($this->instructionList);
        $this->movsx = new Movsx($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->movzx;
    }

    // ========================================
    // MOVZX r32, r/m8 (0x0F 0xB6) Tests
    // ========================================

    public function testMovzxR32R8(): void
    {
        // MOVZX EAX, CL (ModRM = 0xC1 = 11 000 001)
        $this->setRegister(RegisterType::ECX, 0x000000AB);
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);

        $this->executeTwoByteOpWith($this->movzx, 0xB6, [0xC1]);

        $this->assertSame(0x000000AB, $this->getRegister(RegisterType::EAX));
    }

    public function testMovzxR32M8(): void
    {
        // MOVZX EAX, BYTE PTR [0x1000]
        // ModRM = 0x05 = 00 000 101 (disp32)
        $this->writeMemory(0x1000, 0xCD, 8);

        $this->executeTwoByteOpWith($this->movzx, 0xB6, [0x05, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x000000CD, $this->getRegister(RegisterType::EAX));
    }

    public function testMovzxR32R8HighByte(): void
    {
        // MOVZX EAX, CH (ModRM = 0xC5 = 11 000 101)
        $this->setRegister(RegisterType::ECX, 0x0000AB00);
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);

        $this->executeTwoByteOpWith($this->movzx, 0xB6, [0xC5]);

        $this->assertSame(0x000000AB, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // MOVZX r32, r/m16 (0x0F 0xB7) Tests
    // ========================================

    public function testMovzxR32R16(): void
    {
        // MOVZX EAX, CX (ModRM = 0xC1 = 11 000 001)
        $this->setRegister(RegisterType::ECX, 0xFFFF1234);
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);

        $this->executeTwoByteOpWith($this->movzx, 0xB7, [0xC1]);

        $this->assertSame(0x00001234, $this->getRegister(RegisterType::EAX));
    }

    public function testMovzxR32M16(): void
    {
        // MOVZX EAX, WORD PTR [0x1000]
        $this->writeMemory(0x1000, 0x5678, 16);

        $this->executeTwoByteOpWith($this->movzx, 0xB7, [0x05, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x00005678, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // MOVSX r32, r/m8 (0x0F 0xBE) Tests
    // ========================================

    public function testMovsxR32R8Positive(): void
    {
        // MOVSX EAX, CL with positive value (0x7F)
        $this->setRegister(RegisterType::ECX, 0x0000007F);
        $this->setRegister(RegisterType::EAX, 0x00000000);

        $this->executeTwoByteOpWith($this->movsx, 0xBE, [0xC1]);

        $this->assertSame(0x0000007F, $this->getRegister(RegisterType::EAX));
    }

    public function testMovsxR32R8Negative(): void
    {
        // MOVSX EAX, CL with negative value (0x80 = -128)
        $this->setRegister(RegisterType::ECX, 0x00000080);
        $this->setRegister(RegisterType::EAX, 0x00000000);

        $this->executeTwoByteOpWith($this->movsx, 0xBE, [0xC1]);

        $this->assertSame(0xFFFFFF80, $this->getRegister(RegisterType::EAX));
    }

    public function testMovsxR32R8FullNegative(): void
    {
        // MOVSX EAX, CL with 0xFF (-1)
        $this->setRegister(RegisterType::ECX, 0x000000FF);
        $this->setRegister(RegisterType::EAX, 0x00000000);

        $this->executeTwoByteOpWith($this->movsx, 0xBE, [0xC1]);

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    public function testMovsxR32M8(): void
    {
        // MOVSX EAX, BYTE PTR [0x1000] with 0xFE (-2)
        $this->writeMemory(0x1000, 0xFE, 8);

        $this->executeTwoByteOpWith($this->movsx, 0xBE, [0x05, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0xFFFFFFFE, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // MOVSX r32, r/m16 (0x0F 0xBF) Tests
    // ========================================

    public function testMovsxR32R16Positive(): void
    {
        // MOVSX EAX, CX with positive value (0x7FFF)
        $this->setRegister(RegisterType::ECX, 0x00007FFF);
        $this->setRegister(RegisterType::EAX, 0x00000000);

        $this->executeTwoByteOpWith($this->movsx, 0xBF, [0xC1]);

        $this->assertSame(0x00007FFF, $this->getRegister(RegisterType::EAX));
    }

    public function testMovsxR32R16Negative(): void
    {
        // MOVSX EAX, CX with negative value (0x8000 = -32768)
        $this->setRegister(RegisterType::ECX, 0x00008000);
        $this->setRegister(RegisterType::EAX, 0x00000000);

        $this->executeTwoByteOpWith($this->movsx, 0xBF, [0xC1]);

        $this->assertSame(0xFFFF8000, $this->getRegister(RegisterType::EAX));
    }

    public function testMovsxR32M16(): void
    {
        // MOVSX EAX, WORD PTR [0x1000] with 0xFFFF (-1)
        $this->writeMemory(0x1000, 0xFFFF, 16);

        $this->executeTwoByteOpWith($this->movsx, 0xBF, [0x05, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // 16-bit Operand Size Tests
    // ========================================

    public function testMovzxR16R8(): void
    {
        // MOVZX AX, CL in 16-bit mode
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::ECX, 0x000000AB);
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);

        $this->executeTwoByteOpWith($this->movzx, 0xB6, [0xC1]);

        // Only AX should be affected
        $this->assertSame(0x00AB, $this->getRegister(RegisterType::EAX, 16));
    }

    public function testMovsxR16R8Negative(): void
    {
        // MOVSX AX, CL in 16-bit mode with negative value
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::ECX, 0x00000080);
        $this->setRegister(RegisterType::EAX, 0x00000000);

        $this->executeTwoByteOpWith($this->movsx, 0xBE, [0xC1]);

        $this->assertSame(0xFF80, $this->getRegister(RegisterType::EAX, 16));
    }

    // ========================================
    // Helper method
    // ========================================

    private function executeTwoByteOpWith(InstructionInterface $instruction, int $secondByte, array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | $secondByte;
        $instruction->process($this->runtime, [$opcodeKey]);
    }
}
