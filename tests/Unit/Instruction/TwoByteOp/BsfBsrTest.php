<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Bsf;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Bsr;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for BSF and BSR instructions.
 *
 * BSF r16/32, r/m16/32: 0x0F 0xBC (Bit Scan Forward)
 * BSR r16/32, r/m16/32: 0x0F 0xBD (Bit Scan Reverse)
 */
class BsfBsrTest extends TwoByteOpTestCase
{
    private Bsf $bsf;
    private Bsr $bsr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bsf = new Bsf($this->instructionList);
        $this->bsr = new Bsr($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->bsf;
    }

    // ========================================
    // BSF (Bit Scan Forward) Tests
    // ========================================

    public function testBsfFindFirstBit(): void
    {
        // BSF EAX, ECX where ECX has bit 0 set
        $this->setRegister(RegisterType::ECX, 0x00000001);
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);

        $this->executeBsf([0xC1]); // ModRM: reg=EAX, r/m=ECX

        $this->assertSame(0, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsfFindBit4(): void
    {
        // BSF EAX, ECX where first set bit is at position 4
        $this->setRegister(RegisterType::ECX, 0x00000010);

        $this->executeBsf([0xC1]);

        $this->assertSame(4, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsfFindBit31(): void
    {
        // BSF EAX, ECX where first set bit is at position 31
        $this->setRegister(RegisterType::ECX, 0x80000000);

        $this->executeBsf([0xC1]);

        $this->assertSame(31, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsfMultipleBits(): void
    {
        // BSF EAX, ECX with multiple bits set (should find lowest)
        $this->setRegister(RegisterType::ECX, 0xFF00FF00);

        $this->executeBsf([0xC1]);

        $this->assertSame(8, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsfZeroSetsZeroFlag(): void
    {
        // BSF EAX, ECX where ECX is zero
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->setRegister(RegisterType::EAX, 0x12345678);

        $this->executeBsf([0xC1]);

        // Destination is undefined when source is zero, but ZF must be set
        $this->assertTrue($this->getZeroFlag());
    }

    public function testBsfFromMemory(): void
    {
        // BSF EAX, [0x1000]
        $this->writeMemory(0x1000, 0x00000100, 32); // Bit 8 set

        $this->executeBsf([0x05, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(8, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    // ========================================
    // BSR (Bit Scan Reverse) Tests
    // ========================================

    public function testBsrFindHighestBit(): void
    {
        // BSR EAX, ECX where highest bit is at position 31
        $this->setRegister(RegisterType::ECX, 0x80000000);

        $this->executeBsr([0xC1]);

        $this->assertSame(31, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsrFindBit0(): void
    {
        // BSR EAX, ECX where only bit 0 is set
        $this->setRegister(RegisterType::ECX, 0x00000001);

        $this->executeBsr([0xC1]);

        $this->assertSame(0, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsrFindBit15(): void
    {
        // BSR EAX, ECX where highest set bit is at position 15
        $this->setRegister(RegisterType::ECX, 0x00008000);

        $this->executeBsr([0xC1]);

        $this->assertSame(15, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsrMultipleBits(): void
    {
        // BSR EAX, ECX with multiple bits set (should find highest)
        $this->setRegister(RegisterType::ECX, 0x00FF00FF);

        $this->executeBsr([0xC1]);

        $this->assertSame(23, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsrZeroSetsZeroFlag(): void
    {
        // BSR EAX, ECX where ECX is zero
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->setRegister(RegisterType::EAX, 0x12345678);

        $this->executeBsr([0xC1]);

        $this->assertTrue($this->getZeroFlag());
    }

    public function testBsrFromMemory(): void
    {
        // BSR EAX, [0x1000]
        $this->writeMemory(0x1000, 0x00100000, 32); // Bit 20 set

        $this->executeBsr([0x05, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(20, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    // ========================================
    // 16-bit Mode Tests
    // ========================================

    public function testBsf16Bit(): void
    {
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::ECX, 0x0080); // Bit 7 set

        $this->executeBsf([0xC1]);

        $this->assertSame(7, $this->getRegister(RegisterType::EAX, 16));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsr16Bit(): void
    {
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::ECX, 0x4000); // Bit 14 set

        $this->executeBsr([0xC1]);

        $this->assertSame(14, $this->getRegister(RegisterType::EAX, 16));
        $this->assertFalse($this->getZeroFlag());
    }

    // ========================================
    // Long mode 64-bit Tests
    // ========================================

    public function testBsf64BitFindBit32(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        $this->setRegister(RegisterType::ECX, 0x0000000100000000, 64); // Bit 32 set

        // BSF RAX, RCX
        $this->executeBsf([0xC1]);

        $this->assertSame(32, $this->getRegister(RegisterType::EAX, 64));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsr64BitFindBit63(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        $this->setRegister(RegisterType::ECX, PHP_INT_MIN, 64); // 0x8000000000000000

        // BSR RAX, RCX
        $this->executeBsr([0xC1]);

        $this->assertSame(63, $this->getRegister(RegisterType::EAX, 64));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testBsf64BitWithRexRAndRexB(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x0D); // REX.W | REX.R | REX.B

        // ModRM 0xC1: reg=0, r/m=1 => R8, R9 with REX.R/REX.B
        $this->setRegister(RegisterType::R9, 0x0000000000000010, 64);
        $this->setRegister(RegisterType::R8, 0, 64);

        $this->executeBsf([0xC1]);

        $this->assertSame(4, $this->getRegister(RegisterType::R8, 64));
        $this->assertFalse($this->getZeroFlag());
    }

    // ========================================
    // Helper methods
    // ========================================

    private function executeBsf(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xBC;
        $this->bsf->process($this->runtime, [$opcodeKey]);
    }

    private function executeBsr(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xBD;
        $this->bsr->process($this->runtime, [$opcodeKey]);
    }
}
