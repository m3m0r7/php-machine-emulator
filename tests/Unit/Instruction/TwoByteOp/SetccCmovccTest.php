<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Setcc;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Cmovcc;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for SETcc and CMOVcc instructions.
 *
 * SETcc r/m8: 0x0F 0x90-0x9F
 * CMOVcc r16/32, r/m16/32: 0x0F 0x40-0x4F
 */
class SetccCmovccTest extends TwoByteOpTestCase
{
    private Setcc $setcc;
    private Cmovcc $cmovcc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setcc = new Setcc($this->instructionList);
        $this->cmovcc = new Cmovcc($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->setcc;
    }

    // ========================================
    // SETZ/SETE (0x0F 0x94) Tests
    // ========================================

    public function testSetzWhenZeroFlagSet(): void
    {
        // SETZ CL when ZF=1
        $this->setZeroFlag(true);
        $this->setRegister(RegisterType::ECX, 0x00000000);

        $this->executeSetcc(0x94, [0xC1]); // ModRM: r/m = CL

        $this->assertSame(1, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    public function testSetzWhenZeroFlagClear(): void
    {
        // SETZ CL when ZF=0
        $this->setZeroFlag(false);
        $this->setRegister(RegisterType::ECX, 0x000000FF);

        $this->executeSetcc(0x94, [0xC1]);

        $this->assertSame(0, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    // ========================================
    // SETNZ/SETNE (0x0F 0x95) Tests
    // ========================================

    public function testSetnzWhenZeroFlagClear(): void
    {
        // SETNZ CL when ZF=0
        $this->setZeroFlag(false);

        $this->executeSetcc(0x95, [0xC1]);

        $this->assertSame(1, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    public function testSetnzWhenZeroFlagSet(): void
    {
        // SETNZ CL when ZF=1
        $this->setZeroFlag(true);

        $this->executeSetcc(0x95, [0xC1]);

        $this->assertSame(0, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    // ========================================
    // SETC/SETB (0x0F 0x92) Tests
    // ========================================

    public function testSetcWhenCarrySet(): void
    {
        // SETC CL when CF=1
        $this->setCarryFlag(true);

        $this->executeSetcc(0x92, [0xC1]);

        $this->assertSame(1, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    public function testSetcWhenCarryClear(): void
    {
        // SETC CL when CF=0
        $this->setCarryFlag(false);

        $this->executeSetcc(0x92, [0xC1]);

        $this->assertSame(0, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    // ========================================
    // SETNC/SETAE (0x0F 0x93) Tests
    // ========================================

    public function testSetncWhenCarryClear(): void
    {
        // SETNC CL when CF=0
        $this->setCarryFlag(false);

        $this->executeSetcc(0x93, [0xC1]);

        $this->assertSame(1, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    // ========================================
    // SETS (0x0F 0x98) Tests
    // ========================================

    public function testSetsWhenSignSet(): void
    {
        // SETS CL when SF=1
        $this->setSignFlag(true);

        $this->executeSetcc(0x98, [0xC1]);

        $this->assertSame(1, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    public function testSetsWhenSignClear(): void
    {
        // SETS CL when SF=0
        $this->setSignFlag(false);

        $this->executeSetcc(0x98, [0xC1]);

        $this->assertSame(0, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    // ========================================
    // SETcc to Memory Tests
    // ========================================

    public function testSetccToMemory(): void
    {
        // SETZ [0x1000] when ZF=1
        $this->setZeroFlag(true);
        $this->writeMemory(0x1000, 0xFF, 8);

        $this->executeSetcc(0x94, [0x05, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(1, $this->readMemory(0x1000, 8));
    }

    // ========================================
    // CMOVZ/CMOVE (0x0F 0x44) Tests
    // ========================================

    public function testCmovzWhenZeroFlagSet(): void
    {
        // CMOVZ EAX, ECX when ZF=1 - should move
        $this->setZeroFlag(true);
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::ECX, 0x22222222);

        $this->executeCmovcc(0x44, [0xC1]); // ModRM: reg=EAX, r/m=ECX

        $this->assertSame(0x22222222, $this->getRegister(RegisterType::EAX));
    }

    public function testCmovzWhenZeroFlagClear(): void
    {
        // CMOVZ EAX, ECX when ZF=0 - should NOT move
        $this->setZeroFlag(false);
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::ECX, 0x22222222);

        $this->executeCmovcc(0x44, [0xC1]);

        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EAX)); // Unchanged
    }

    // ========================================
    // CMOVNZ/CMOVNE (0x0F 0x45) Tests
    // ========================================

    public function testCmovnzWhenZeroFlagClear(): void
    {
        // CMOVNZ EAX, ECX when ZF=0 - should move
        $this->setZeroFlag(false);
        $this->setRegister(RegisterType::EAX, 0xAAAAAAAA);
        $this->setRegister(RegisterType::ECX, 0xBBBBBBBB);

        $this->executeCmovcc(0x45, [0xC1]);

        $this->assertSame(0xBBBBBBBB, $this->getRegister(RegisterType::EAX));
    }

    public function testCmovnzWhenZeroFlagSet(): void
    {
        // CMOVNZ EAX, ECX when ZF=1 - should NOT move
        $this->setZeroFlag(true);
        $this->setRegister(RegisterType::EAX, 0xAAAAAAAA);
        $this->setRegister(RegisterType::ECX, 0xBBBBBBBB);

        $this->executeCmovcc(0x45, [0xC1]);

        $this->assertSame(0xAAAAAAAA, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // CMOVC/CMOVB (0x0F 0x42) Tests
    // ========================================

    public function testCmovcWhenCarrySet(): void
    {
        $this->setCarryFlag(true);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setRegister(RegisterType::ECX, 0xDEADBEEF);

        $this->executeCmovcc(0x42, [0xC1]);

        $this->assertSame(0xDEADBEEF, $this->getRegister(RegisterType::EAX));
    }

    public function testCmovcWhenCarryClear(): void
    {
        $this->setCarryFlag(false);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setRegister(RegisterType::ECX, 0xDEADBEEF);

        $this->executeCmovcc(0x42, [0xC1]);

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // CMOVcc from Memory Tests
    // ========================================

    public function testCmovccFromMemory(): void
    {
        // CMOVZ EAX, [0x1000] when ZF=1
        $this->setZeroFlag(true);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->writeMemory(0x1000, 0xCAFEBABE, 32);

        $this->executeCmovcc(0x44, [0x05, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0xCAFEBABE, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // 16-bit Mode Tests
    // ========================================

    public function testCmovcc16Bit(): void
    {
        $this->setOperandSize(16);
        $this->setZeroFlag(true);
        $this->setRegister(RegisterType::EAX, 0xFFFF0000);
        $this->setRegister(RegisterType::ECX, 0x00001234);

        $this->executeCmovcc(0x44, [0xC1]);

        $this->assertSame(0x1234, $this->getRegister(RegisterType::EAX, 16));
    }

    // ========================================
    // Helper methods
    // ========================================

    private function executeSetcc(int $condition, array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | $condition;
        $this->setcc->process($this->runtime, [$opcodeKey]);
    }

    private function executeCmovcc(int $condition, array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | $condition;
        $this->cmovcc->process($this->runtime, [$opcodeKey]);
    }
}
