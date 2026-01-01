<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Pshufd;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\PshiftDq;

class PshufdPshiftDqTest extends TwoByteOpTestCase
{
    private Pshufd $pshufd;
    private PshiftDq $pshiftDq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pshufd = new Pshufd($this->instructionList);
        $this->pshiftDq = new PshiftDq($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->pshufd;
    }

    public function testPshufdRegisterToRegisterReversesDwords(): void
    {
        $this->cpuContext->setXmm(1, [0x11111111, 0x22222222, 0x33333333, 0x44444444]);

        // PSHUFD XMM2, XMM1, 0x1B (reverse) => 66 0F 70 D1 1B
        $this->executePshufd(0xD1, 0x1B);

        $this->assertSame([0x44444444, 0x33333333, 0x22222222, 0x11111111], $this->cpuContext->getXmm(2));
    }

    public function testPshufdUsesRexToAccessXmm8PlusIn64BitMode(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x05); // REX.R | REX.B

        $this->cpuContext->setXmm(9, [1, 2, 3, 4]);

        // PSHUFD XMM8, XMM9, 0x00 (broadcast dword0)
        // modrm 11 reg=000 rm=001 => 0xC1, with REX.R/B => XMM8, XMM9
        $this->executePshufd(0xC1, 0x00);

        $this->assertSame([1, 1, 1, 1], $this->cpuContext->getXmm(8));
    }

    public function testPslldqShiftsLeftByBytes(): void
    {
        // Bytes 00..0F => dwords 03020100 07060504 0B0A0908 0F0E0D0C
        $this->cpuContext->setXmm(1, [0x03020100, 0x07060504, 0x0B0A0908, 0x0F0E0D0C]);

        // PSLLDQ XMM1, 4 => 66 0F 73 F9 04
        $this->executePshiftDq(0xF9, 0x04);

        $this->assertSame([0x00000000, 0x03020100, 0x07060504, 0x0B0A0908], $this->cpuContext->getXmm(1));
    }

    public function testPsrldqShiftsRightByBytes(): void
    {
        $this->cpuContext->setXmm(1, [0x03020100, 0x07060504, 0x0B0A0908, 0x0F0E0D0C]);

        // PSRLDQ XMM1, 4 => 66 0F 73 D9 04
        $this->executePshiftDq(0xD9, 0x04);

        $this->assertSame([0x07060504, 0x0B0A0908, 0x0F0E0D0C, 0x00000000], $this->cpuContext->getXmm(1));
    }

    public function testPshiftDqCountGreaterThan16ZerosResult(): void
    {
        $this->cpuContext->setXmm(1, [0x11111111, 0x22222222, 0x33333333, 0x44444444]);

        // PSLLDQ XMM1, 32 (count>16) => zero
        $this->executePshiftDq(0xF9, 0x20);
        $this->assertSame([0, 0, 0, 0], $this->cpuContext->getXmm(1));
    }

    private function executePshufd(int $modrm, int $imm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm) . chr($imm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0x70;
        $this->pshufd->process($this->runtime, [0x66, $opcodeKey]);
    }

    private function executePshiftDq(int $modrm, int $imm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm) . chr($imm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0x73;
        $this->pshiftDq->process($this->runtime, [0x66, $opcodeKey]);
    }
}
