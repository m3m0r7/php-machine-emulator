<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Pxor;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Xorps;
use PHPMachineEmulator\Instruction\RegisterType;

class XorpsPxorTest extends TwoByteOpTestCase
{
    private Xorps $xorps;
    private Pxor $pxor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->xorps = new Xorps($this->instructionList);
        $this->pxor = new Pxor($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->xorps;
    }

    public function testXorpsRegisterToRegister(): void
    {
        $this->cpuContext->setXmm(1, [0xFFFFFFFF, 0x00000000, 0x12345678, 0x80000000]);
        $this->cpuContext->setXmm(2, [0x0F0F0F0F, 0xFFFFFFFF, 0x87654321, 0x80000000]);

        // XORPS XMM1, XMM2 (0F 57 CA)
        $this->executeXorps(0xCA);

        $this->assertSame(
            [0xF0F0F0F0, 0xFFFFFFFF, 0x95511559, 0x00000000],
            $this->cpuContext->getXmm(1),
        );
        $this->assertSame([0x0F0F0F0F, 0xFFFFFFFF, 0x87654321, 0x80000000], $this->cpuContext->getXmm(2));
    }

    public function testXorpsMemoryOperand(): void
    {
        $this->setRegister(RegisterType::EAX, 0x4000, 32);
        $this->writeM128(0x4000, [0xAAAAAAAA, 0xBBBBBBBB, 0xCCCCCCCC, 0xDDDDDDDD]);

        $this->cpuContext->setXmm(1, [0xFFFFFFFF, 0, 0x12345678, 0x80000000]);

        // XORPS XMM1, [EAX] (0F 57 08)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x08));
        $this->memoryStream->setOffset(0);
        $opcodeKey = (0x0F << 8) | 0x57;
        $this->xorps->process($this->runtime, [$opcodeKey]);

        $this->assertSame(
            [0x55555555, 0xBBBBBBBB, 0xDEF89AB4, 0x5DDDDDDD],
            $this->cpuContext->getXmm(1),
        );
    }

    public function testXorpsUsesRexToAccessXmm8PlusIn64BitMode(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x05); // REX.R | REX.B

        $this->cpuContext->setXmm(8, [0x01020304, 0, 0, 0]);
        $this->cpuContext->setXmm(9, [0x11111111, 0, 0, 0]);

        // XORPS XMM8, XMM9 => modrm 11 000 001 (0xC1) + REX.R/B
        $this->executeXorps(0xC1);

        $this->assertSame([0x10131215, 0, 0, 0], $this->cpuContext->getXmm(8));
    }

    public function testPxorRegisterToRegister(): void
    {
        $this->cpuContext->setXmm(3, [0xAAAAAAAA, 0x55555555, 0xFFFFFFFF, 0]);
        $this->cpuContext->setXmm(4, [0x0F0F0F0F, 0xFFFFFFFF, 0x00000000, 0xFFFFFFFF]);

        // PXOR XMM3, XMM4 (66 0F EF DC)
        $this->executePxor(0xDC);

        $this->assertSame([0xA5A5A5A5, 0xAAAAAAAA, 0xFFFFFFFF, 0xFFFFFFFF], $this->cpuContext->getXmm(3));
    }

    private function executeXorps(int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0x57;
        $this->xorps->process($this->runtime, [$opcodeKey]);
    }

    private function executePxor(int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xEF;
        $this->pxor->process($this->runtime, [0x66, $opcodeKey]);
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
