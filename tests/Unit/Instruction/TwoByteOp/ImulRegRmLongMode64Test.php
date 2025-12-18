<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\ImulRegRm;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

class ImulRegRmLongMode64Test extends TwoByteOpTestCase
{
    private ImulRegRm $imul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->imul = new ImulRegRm($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->imul;
    }

    public function testImulR64R64NoOverflow(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        $this->setRegister(RegisterType::EAX, 2, 64); // RAX (dest)
        $this->setRegister(RegisterType::ECX, 3, 64); // RCX (src)

        // IMUL RAX, RCX (ModRM: 11 000 001 = 0xC1)
        $this->executeImul([0xC1]);

        $this->assertSame(6, $this->getRegister(RegisterType::EAX, 64));
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
    }

    public function testImulR64R64OverflowSetsCarryAndOverflow(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        $this->setRegister(RegisterType::EAX, 0x4000000000000000, 64); // 2^62
        $this->setRegister(RegisterType::ECX, 4, 64);

        $this->executeImul([0xC1]);

        $this->assertSame('0x0000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testImulR8R9WithRexRAndRexB(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x0D); // REX.W | REX.R | REX.B

        $this->setRegister(RegisterType::R8, 2, 64);
        $this->setRegister(RegisterType::R9, 5, 64);

        // ModRM 0xC1: reg=0, r/m=1 => R8, R9 with REX.R/REX.B
        $this->executeImul([0xC1]);

        $this->assertSame(10, $this->getRegister(RegisterType::R8, 64));
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
    }

    private function executeImul(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xAF;
        $this->imul->process($this->runtime, [$opcodeKey]);
    }
}

