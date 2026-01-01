<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Fxsave;
use PHPMachineEmulator\Instruction\RegisterType;

class FxsaveSimdStateTest extends TwoByteOpTestCase
{
    private Fxsave $fxsave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fxsave = new Fxsave($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->fxsave;
    }

    public function testFxsaveFxrstorRestoresXmmAndMxcsrIn64BitMode(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->setRegister(RegisterType::EAX, 0x2000, 64);

        $this->cpuContext->setMxcsr(0x00001F80);
        $this->cpuContext->setXmm(0, [0x11111111, 0x22222222, 0x33333333, 0x44444444]);
        $this->cpuContext->setXmm(15, [0xAAAAAAAA, 0xBBBBBBBB, 0xCCCCCCCC, 0xDDDDDDDD]);

        $this->execute0FAE(0x00); // /0 FXSAVE [RAX]

        $this->assertSame(0x00001F80, $this->readMemory(0x2000 + 24, 32));

        $this->cpuContext->setMxcsr(0x00001234);
        $this->cpuContext->setXmm(0, [0, 0, 0, 0]);
        $this->cpuContext->setXmm(15, [0, 0, 0, 0]);

        $this->execute0FAE(0x08); // /1 FXRSTOR [RAX]

        $this->assertSame(0x00001F80, $this->cpuContext->mxcsr());
        $this->assertSame([0x11111111, 0x22222222, 0x33333333, 0x44444444], $this->cpuContext->getXmm(0));
        $this->assertSame([0xAAAAAAAA, 0xBBBBBBBB, 0xCCCCCCCC, 0xDDDDDDDD], $this->cpuContext->getXmm(15));
    }

    public function testFxsaveFxrstorRestoresOnlyXmm0ToXmm7In32BitMode(): void
    {
        $this->setRegister(RegisterType::EAX, 0x2400, 32);

        $this->cpuContext->setMxcsr(0x00001F80);
        $this->cpuContext->setXmm(0, [1, 2, 3, 4]);
        $this->cpuContext->setXmm(7, [5, 6, 7, 8]);
        $this->cpuContext->setXmm(15, [9, 10, 11, 12]);

        $this->execute0FAE(0x00); // /0 FXSAVE [EAX]

        $this->cpuContext->setMxcsr(0x0000AAAA);
        $this->cpuContext->setXmm(0, [0, 0, 0, 0]);
        $this->cpuContext->setXmm(7, [0, 0, 0, 0]);
        $this->cpuContext->setXmm(15, [0xDEAD, 0xBEEF, 0xCAFE, 0xBABE]);

        $this->execute0FAE(0x08); // /1 FXRSTOR [EAX]

        $this->assertSame(0x00001F80, $this->cpuContext->mxcsr());
        $this->assertSame([1, 2, 3, 4], $this->cpuContext->getXmm(0));
        $this->assertSame([5, 6, 7, 8], $this->cpuContext->getXmm(7));
        $this->assertSame([0xDEAD, 0xBEEF, 0xCAFE, 0xBABE], $this->cpuContext->getXmm(15), 'XMM15 is not restored in 32-bit mode');
    }

    public function testStmxcsrAndLdmxcsr(): void
    {
        $this->setRegister(RegisterType::EAX, 0x3000, 32);

        $this->cpuContext->setMxcsr(0x00001234);
        $this->execute0FAE(0x18); // /3 STMXCSR [EAX]
        $this->assertSame(0x00001234, $this->readMemory(0x3000, 32));

        $this->writeMemory(0x3000, 0x00005678, 32);
        $this->execute0FAE(0x10); // /2 LDMXCSR [EAX]
        $this->assertSame(0x00005678, $this->cpuContext->mxcsr());
    }

    private function execute0FAE(int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xAE;
        $this->fxsave->process($this->runtime, [$opcodeKey]);
    }
}
