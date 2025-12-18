<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Andnps;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Andps;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Orps;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Pand;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Pandn;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Por;
use PHPMachineEmulator\Instruction\RegisterType;

class XmmLogicOpsTest extends TwoByteOpTestCase
{
    private Andps $andps;
    private Andnps $andnps;
    private Orps $orps;
    private Pand $pand;
    private Pandn $pandn;
    private Por $por;

    protected function setUp(): void
    {
        parent::setUp();
        $this->andps = new Andps($this->instructionList);
        $this->andnps = new Andnps($this->instructionList);
        $this->orps = new Orps($this->instructionList);
        $this->pand = new Pand($this->instructionList);
        $this->pandn = new Pandn($this->instructionList);
        $this->por = new Por($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->andps;
    }

    public function testAndpsRegisterToRegister(): void
    {
        $this->cpuContext->setXmm(1, [0xFFFFFFFF, 0x00000000, 0x12345678, 0x80000000]);
        $this->cpuContext->setXmm(2, [0x0F0F0F0F, 0xFFFFFFFF, 0x87654321, 0x80000000]);

        // ANDPS XMM1, XMM2 (0F 54 CA)
        $this->executeTwoByteOpWithModrm($this->andps, 0x54, 0xCA);

        $this->assertSame(
            [0x0F0F0F0F, 0x00000000, 0x02244220, 0x80000000],
            $this->cpuContext->getXmm(1),
        );
    }

    public function testOrpsMemoryOperand(): void
    {
        $this->setRegister(RegisterType::EAX, 0x7000, 32);
        $this->writeM128(0x7000, [0xAAAAAAAA, 0xBBBBBBBB, 0xCCCCCCCC, 0xDDDDDDDD]);

        $this->cpuContext->setXmm(1, [0x00000000, 0x11111111, 0x22222222, 0x33333333]);

        // ORPS XMM1, [EAX] (0F 56 08)
        $this->executeTwoByteOpWithModrm($this->orps, 0x56, 0x08);

        $this->assertSame(
            [0xAAAAAAAA, 0xBBBBBBBB, 0xEEEEEEEE, 0xFFFFFFFF],
            $this->cpuContext->getXmm(1),
        );
    }

    public function testAndnpsUsesRexToAccessXmm8PlusIn64BitMode(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x05); // REX.R | REX.B

        $this->cpuContext->setXmm(8, [0xFFFFFFFF, 0x00000000, 0x12345678, 0x80000000]);
        $this->cpuContext->setXmm(9, [0x0F0F0F0F, 0xFFFFFFFF, 0x87654321, 0x80000000]);

        // ANDNPS XMM8, XMM9 (0F 55 C1) with REX.R/B
        $this->executeTwoByteOpWithModrm($this->andnps, 0x55, 0xC1);

        $this->assertSame(
            [0x00000000, 0xFFFFFFFF, 0x85410101, 0x00000000],
            $this->cpuContext->getXmm(8),
        );
    }

    public function testPandPorPandnRegisterToRegister(): void
    {
        $this->cpuContext->setXmm(3, [0xAAAAAAAA, 0x55555555, 0xFFFFFFFF, 0x00000000]);
        $this->cpuContext->setXmm(4, [0x0F0F0F0F, 0xFFFFFFFF, 0x00000000, 0xFFFFFFFF]);

        // PAND XMM3, XMM4 (66 0F DB DC)
        $this->executeTwoByteOpWithPrefixAndModrm($this->pand, 0x66, 0xDB, 0xDC);
        $this->assertSame([0x0A0A0A0A, 0x55555555, 0x00000000, 0x00000000], $this->cpuContext->getXmm(3));

        // Reset XMM3 and run POR/PANDN against XMM4.
        $this->cpuContext->setXmm(3, [0xAAAAAAAA, 0x55555555, 0xFFFFFFFF, 0x00000000]);

        // POR XMM3, XMM4 (66 0F EB DC)
        $this->executeTwoByteOpWithPrefixAndModrm($this->por, 0x66, 0xEB, 0xDC);
        $this->assertSame([0xAFAFAFAF, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF], $this->cpuContext->getXmm(3));

        // Reset XMM3 and run PANDN (dest = ~dest & src).
        $this->cpuContext->setXmm(3, [0xAAAAAAAA, 0x55555555, 0xFFFFFFFF, 0x00000000]);

        // PANDN XMM3, XMM4 (66 0F DF DC)
        $this->executeTwoByteOpWithPrefixAndModrm($this->pandn, 0x66, 0xDF, 0xDC);
        $this->assertSame([0x05050505, 0xAAAAAAAA, 0x00000000, 0xFFFFFFFF], $this->cpuContext->getXmm(3));
    }

    private function executeTwoByteOpWithModrm(InstructionInterface $instruction, int $secondByte, int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | ($secondByte & 0xFF);
        $instruction->process($this->runtime, [$opcodeKey]);
    }

    private function executeTwoByteOpWithPrefixAndModrm(InstructionInterface $instruction, int $prefix, int $secondByte, int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | ($secondByte & 0xFF);
        $instruction->process($this->runtime, [$prefix, $opcodeKey]);
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

