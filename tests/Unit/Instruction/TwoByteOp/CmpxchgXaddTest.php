<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Cmpxchg;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Xadd;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

/**
 * Tests for CMPXCHG and XADD instructions.
 *
 * CMPXCHG r/m8, r8: 0x0F 0xB0
 * CMPXCHG r/m16/32, r16/32: 0x0F 0xB1
 * XADD r/m8, r8: 0x0F 0xC0
 * XADD r/m16/32, r16/32: 0x0F 0xC1
 */
class CmpxchgXaddTest extends TwoByteOpTestCase
{
    private Cmpxchg $cmpxchg;
    private Xadd $xadd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cmpxchg = new Cmpxchg($this->instructionList);
        $this->xadd = new Xadd($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->cmpxchg;
    }

    // ========================================
    // CMPXCHG r/m32, r32 (0x0F 0xB1) Tests
    // ========================================

    public function testCmpxchgR32R32Equal(): void
    {
        // CMPXCHG ECX, EBX - when EAX == ECX, exchange ECX with EBX
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::ECX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0xDEADBEEF);

        // ModRM = 0xCB = 11 001 011 (reg=ECX, r/m=EBX -> dest=ECX, src=EBX)
        // Wait, CMPXCHG r/m, r means: compare EAX with r/m, if equal, load r into r/m
        // So CMPXCHG ECX, EBX: compare EAX with ECX, if equal, ECX = EBX
        // ModRM = 0xD9 = 11 011 001 (reg=EBX, r/m=ECX)
        $this->executeCmpxchg32([0xD9]);

        $this->assertSame(0xDEADBEEF, $this->getRegister(RegisterType::ECX));
        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX)); // EAX unchanged
        $this->assertTrue($this->getZeroFlag()); // ZF=1 because equal
    }

    public function testCmpxchgR32R32NotEqual(): void
    {
        // CMPXCHG ECX, EBX - when EAX != ECX, load ECX into EAX
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::ECX, 0x22222222);
        $this->setRegister(RegisterType::EBX, 0xDEADBEEF);

        $this->executeCmpxchg32([0xD9]);

        $this->assertSame(0x22222222, $this->getRegister(RegisterType::ECX)); // ECX unchanged
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::EAX)); // EAX = old ECX
        $this->assertFalse($this->getZeroFlag()); // ZF=0 because not equal
    }

    public function testCmpxchgMem32Equal(): void
    {
        // CMPXCHG [0x1000], EBX - when EAX == [0x1000], store EBX to memory
        $this->setRegister(RegisterType::EAX, 0xCAFEBABE);
        $this->setRegister(RegisterType::EBX, 0x55667788);
        $this->writeMemory(0x1000, 0xCAFEBABE, 32);

        // ModRM = 0x1D = 00 011 101 (reg=EBX, r/m=disp32)
        $this->executeCmpxchg32([0x1D, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x55667788, $this->readMemory(0x1000, 32));
        $this->assertTrue($this->getZeroFlag());
    }

    public function testCmpxchgMem32NotEqual(): void
    {
        // CMPXCHG [0x1000], EBX - when EAX != [0x1000], load [0x1000] into EAX
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::EBX, 0x55667788);
        $this->writeMemory(0x1000, 0x99AABBCC, 32);

        $this->executeCmpxchg32([0x1D, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x99AABBCC, $this->readMemory(0x1000, 32)); // Memory unchanged
        $this->assertSame(0x99AABBCC, $this->getRegister(RegisterType::EAX)); // EAX = old memory
        $this->assertFalse($this->getZeroFlag());
    }

    // ========================================
    // CMPXCHG r/m8, r8 (0x0F 0xB0) Tests
    // ========================================

    public function testCmpxchg8Equal(): void
    {
        // CMPXCHG CL, BL - when AL == CL, exchange
        $this->setRegister(RegisterType::EAX, 0x000000AB);
        $this->setRegister(RegisterType::ECX, 0x000000AB);
        $this->setRegister(RegisterType::EBX, 0x000000CD);

        $this->executeCmpxchg8([0xD9]);

        $this->assertSame(0xCD, $this->getRegister(RegisterType::ECX) & 0xFF);
        $this->assertTrue($this->getZeroFlag());
    }

    public function testCmpxchg8NotEqual(): void
    {
        // CMPXCHG CL, BL - when AL != CL
        $this->setRegister(RegisterType::EAX, 0x00000011);
        $this->setRegister(RegisterType::ECX, 0x00000022);
        $this->setRegister(RegisterType::EBX, 0x000000CD);

        $this->executeCmpxchg8([0xD9]);

        $this->assertSame(0x22, $this->getRegister(RegisterType::ECX) & 0xFF); // CL unchanged
        $this->assertSame(0x22, $this->getRegister(RegisterType::EAX) & 0xFF); // AL = old CL
        $this->assertFalse($this->getZeroFlag());
    }

    // ========================================
    // XADD r/m32, r32 (0x0F 0xC1) Tests
    // ========================================

    public function testXaddR32R32(): void
    {
        // XADD ECX, EBX - ECX = ECX + EBX, EBX = old ECX
        $this->setRegister(RegisterType::ECX, 0x00000010);
        $this->setRegister(RegisterType::EBX, 0x00000005);

        $this->executeXadd32([0xD9]);

        $this->assertSame(0x00000015, $this->getRegister(RegisterType::ECX)); // 0x10 + 0x05
        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EBX)); // old ECX
    }

    public function testXaddMem32(): void
    {
        // XADD [0x1000], EBX
        $this->writeMemory(0x1000, 0x00000100, 32);
        $this->setRegister(RegisterType::EBX, 0x00000050);

        $this->executeXadd32([0x1D, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x00000150, $this->readMemory(0x1000, 32));
        $this->assertSame(0x00000100, $this->getRegister(RegisterType::EBX)); // old [0x1000]
    }

    public function testXaddWithOverflow(): void
    {
        // XADD ECX, EBX with carry
        $this->setRegister(RegisterType::ECX, 0xFFFFFFFF);
        $this->setRegister(RegisterType::EBX, 0x00000002);

        $this->executeXadd32([0xD9]);

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::ECX)); // Wrapped
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EBX));
        $this->assertTrue($this->getCarryFlag());
    }

    public function testXaddSetsZeroFlag(): void
    {
        // XADD ECX, EBX resulting in zero
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->setRegister(RegisterType::EBX, 0x00000000);

        $this->executeXadd32([0xD9]);

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::ECX));
        $this->assertTrue($this->getZeroFlag());
    }

    // ========================================
    // XADD r/m8, r8 (0x0F 0xC0) Tests
    // ========================================

    public function testXadd8(): void
    {
        // XADD CL, BL
        $this->setRegister(RegisterType::ECX, 0x00000030);
        $this->setRegister(RegisterType::EBX, 0x00000020);

        $this->executeXadd8([0xD9]);

        $this->assertSame(0x50, $this->getRegister(RegisterType::ECX) & 0xFF);
        $this->assertSame(0x30, $this->getRegister(RegisterType::EBX) & 0xFF);
    }

    // ========================================
    // Long mode 64-bit Tests
    // ========================================

    public function testCmpxchgR64R64Equal(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        $this->setRegister(RegisterType::EAX, 0x1122334455667788, 64); // RAX
        $this->setRegister(RegisterType::ECX, 0x1122334455667788, 64); // RCX (dest)
        $this->setRegister(RegisterType::EBX, -1, 64); // RBX (src)

        // CMPXCHG RCX, RBX => ModRM reg=RBX(3), r/m=RCX(1) => 0xD9
        $this->executeCmpxchg64([0xD9]);

        $this->assertSame('0xffffffffffffffff', UInt64::of($this->getRegister(RegisterType::ECX, 64))->toHex());
        $this->assertSame('0x1122334455667788', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getZeroFlag());
    }

    public function testCmpxchgR64R64NotEqual(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        $this->setRegister(RegisterType::EAX, 0x0000000000000001, 64); // RAX
        $this->setRegister(RegisterType::ECX, 0x1122334455667788, 64); // RCX (dest)
        $this->setRegister(RegisterType::EBX, 0x99, 64); // RBX (src)

        $this->executeCmpxchg64([0xD9]);

        $this->assertSame('0x1122334455667788', UInt64::of($this->getRegister(RegisterType::ECX, 64))->toHex()); // dest unchanged
        $this->assertSame('0x1122334455667788', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex()); // RAX loaded
        $this->assertFalse($this->getZeroFlag());
    }

    public function testXaddR64R64(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        // XADD RCX, RBX: RCX = RCX + RBX, RBX = old RCX
        $this->setRegister(RegisterType::ECX, 0x10, 64);
        $this->setRegister(RegisterType::EBX, 0x5, 64);

        $this->executeXadd64([0xD9]);

        $this->assertSame(0x15, $this->getRegister(RegisterType::ECX, 64));
        $this->assertSame(0x10, $this->getRegister(RegisterType::EBX, 64));
    }

    // ========================================
    // Helper methods
    // ========================================

    private function executeCmpxchg32(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xB1;
        $this->cmpxchg->process($this->runtime, [$opcodeKey]);
    }

    private function executeCmpxchg8(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xB0;
        $this->cmpxchg->process($this->runtime, [$opcodeKey]);
    }

    private function executeXadd32(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xC1;
        $this->xadd->process($this->runtime, [$opcodeKey]);
    }

    private function executeXadd8(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xC0;
        $this->xadd->process($this->runtime, [$opcodeKey]);
    }

    private function executeCmpxchg64(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xB1;
        $this->cmpxchg->process($this->runtime, [$opcodeKey]);
    }

    private function executeXadd64(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xC1;
        $this->xadd->process($this->runtime, [$opcodeKey]);
    }
}
