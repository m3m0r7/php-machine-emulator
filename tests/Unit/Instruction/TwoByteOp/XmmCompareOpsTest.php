<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Pcmpeqb;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Pcmpeqd;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Pmovmskb;
use PHPMachineEmulator\Instruction\RegisterType;

final class XmmCompareOpsTest extends TwoByteOpTestCase
{
    private Pcmpeqb $pcmpeqb;
    private Pcmpeqd $pcmpeqd;
    private Pmovmskb $pmovmskb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pcmpeqb = new Pcmpeqb($this->instructionList);
        $this->pcmpeqd = new Pcmpeqd($this->instructionList);
        $this->pmovmskb = new Pmovmskb($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->pcmpeqb;
    }

    public function testPcmpeqbRegisterToRegister(): void
    {
        $this->cpuContext->setXmm(1, [0x01020304, 0xA0B0C0D0, 0xFFFFFFFF, 0x00000000]);
        $this->cpuContext->setXmm(2, [0x01020305, 0xA0B1C0D0, 0xFF00FF00, 0x00000000]);

        // PCMPEQB XMM1, XMM2 (66 0F 74 CA)
        $this->executeSse2WithPrefixAndModrm($this->pcmpeqb, 0x74, 0xCA);

        $this->assertSame(
            [0xFFFFFF00, 0xFF00FFFF, 0xFF00FF00, 0xFFFFFFFF],
            $this->cpuContext->getXmm(1),
        );
    }

    public function testPcmpeqdMemoryOperand(): void
    {
        $this->setRegister(RegisterType::EAX, 0x7000, 32);
        $this->writeM128(0x7000, [0x12345678, 0x00000000, 0xDEADBEEF, 0xFFFFFFFF]);

        $this->cpuContext->setXmm(1, [0x12345678, 0x11111111, 0xDEADBEEF, 0x00000000]);

        // PCMPEQD XMM1, [EAX] (66 0F 76 08)
        $this->executeSse2WithPrefixAndModrm($this->pcmpeqd, 0x76, 0x08);

        $this->assertSame(
            [0xFFFFFFFF, 0x00000000, 0xFFFFFFFF, 0x00000000],
            $this->cpuContext->getXmm(1),
        );
    }

    public function testPmovmskbRegisterToRegister(): void
    {
        $this->cpuContext->setXmm(2, [0x7FFF0080, 0xFF7F8000, 0xFFFE8180, 0x7E7F0100]);

        // PMOVMSKB EAX, XMM2 (66 0F D7 C2)
        $this->executeSse2WithPrefixAndModrm($this->pmovmskb, 0xD7, 0xC2);

        $this->assertSame(0x00000FA5, $this->getRegister(RegisterType::EAX, 32));
    }

    public function testPmovmskbUsesRexToAccessR8AndXmm9In64BitMode(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x05); // REX.R | REX.B

        $this->setRegister(RegisterType::R8, -1, 64);
        $this->cpuContext->setXmm(9, [0x7FFF0080, 0xFF7F8000, 0xFFFE8180, 0x7E7F0100]);

        // PMOVMSKB R8D, XMM9 (66 0F D7 C1) with REX.R/B
        $this->executeSse2WithPrefixAndModrm($this->pmovmskb, 0xD7, 0xC1);

        $this->assertSame(0x0000000000000FA5, $this->getRegister(RegisterType::R8, 64));
    }

    private function executeSse2WithPrefixAndModrm(InstructionInterface $instruction, int $secondByte, int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $instruction->process($this->runtime, [0x66, 0x0F, $secondByte]);
    }

    /**
     * @param array{int,int,int,int} $dwords
     */
    private function writeM128(int $address, array $dwords): void
    {
        $this->writeMemory($address, $dwords[0], 32);
        $this->writeMemory($address + 4, $dwords[1], 32);
        $this->writeMemory($address + 8, $dwords[2], 32);
        $this->writeMemory($address + 12, $dwords[3], 32);
    }
}

