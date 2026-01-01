<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\MovdMovq;
use PHPMachineEmulator\Instruction\RegisterType;

class MovdMovqTest extends TwoByteOpTestCase
{
    private MovdMovq $movdMovq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->movdMovq = new MovdMovq($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->movdMovq;
    }

    public function testMovdXmmFromGpr32ZeroesUpperBits(): void
    {
        $this->setRegister(RegisterType::EAX, 0xDEADBEEF, 32);

        // MOVD XMM1, EAX (66 0F 6E C8)
        $this->executeMovdMovq(0x6E, 0xC8);

        $this->assertSame([0xDEADBEEF, 0, 0, 0], $this->cpuContext->getXmm(1));
    }

    public function testMovdGprFromXmm32WritesLowDword(): void
    {
        $this->cpuContext->setXmm(1, [0x12345678, 0xAAAAAAAA, 0xBBBBBBBB, 0xCCCCCCCC]);

        // MOVD EAX, XMM1 (66 0F 7E C8)
        $this->executeMovdMovq(0x7E, 0xC8);

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX, 32));
    }

    public function testMovqXmmFromGpr64WithRexW(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        $this->setRegister(RegisterType::EAX, 0x1122334455667788, 64);

        // MOVQ XMM1, RAX (66 REX.W 0F 6E C8)
        $this->executeMovdMovq(0x6E, 0xC8);

        $this->assertSame([0x55667788, 0x11223344, 0, 0], $this->cpuContext->getXmm(1));
    }

    public function testMovqGpr64FromXmmWithRexW(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        $this->cpuContext->setXmm(1, [0x55667788, 0x11223344, 0xAAAAAAAA, 0xBBBBBBBB]);

        // MOVQ RAX, XMM1 (66 REX.W 0F 7E C8)
        $this->executeMovdMovq(0x7E, 0xC8);

        $this->assertSame(0x1122334455667788, $this->getRegister(RegisterType::EAX, 64));
    }

    public function testMovqUsesRexBToAccessR8(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x09); // REX.W | REX.B

        $this->setRegister(RegisterType::R8, 0x0102030405060708, 64);

        // MOVQ XMM1, R8 (66 REX.W 0F 6E C8 with REX.B selecting R8)
        $this->executeMovdMovq(0x6E, 0xC8);

        $this->assertSame([0x05060708, 0x01020304, 0, 0], $this->cpuContext->getXmm(1));
    }

    public function testMovdFromMemory(): void
    {
        $this->setRegister(RegisterType::EAX, 0x8000, 32);
        $this->writeMemory(0x8000, 0xCAFEBABE, 32);

        // MOVD XMM1, [EAX] (66 0F 6E 08)
        $this->executeMovdMovq(0x6E, 0x08);

        $this->assertSame([0xCAFEBABE, 0, 0, 0], $this->cpuContext->getXmm(1));
    }

    public function testMovqToMemoryWithRexW(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        $this->setRegister(RegisterType::EAX, 0x9000, 64);
        $this->cpuContext->setXmm(1, [0x89ABCDEF, 0x01234567, 0xAAAAAAAA, 0xBBBBBBBB]);

        // MOVQ [RAX], XMM1 (66 REX.W 0F 7E 08)
        $this->executeMovdMovq(0x7E, 0x08);

        $this->assertSame(0x89ABCDEF, $this->readMemory(0x9000, 32));
        $this->assertSame(0x01234567, $this->readMemory(0x9004, 32));
    }

    private function executeMovdMovq(int $secondByte, int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | ($secondByte & 0xFF);
        $this->movdMovq->process($this->runtime, [0x66, $opcodeKey]);
    }
}
