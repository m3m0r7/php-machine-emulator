<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Movdqa;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Movdqu;
use PHPMachineEmulator\Instruction\RegisterType;

class MovdqaMovdquTest extends TwoByteOpTestCase
{
    private Movdqa $movdqa;
    private Movdqu $movdqu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->movdqa = new Movdqa($this->instructionList);
        $this->movdqu = new Movdqu($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->movdqa;
    }

    public function testMovdqaLoadFromMemoryAligned(): void
    {
        $this->setRegister(RegisterType::EAX, 0x5000, 32);
        $this->writeM128(0x5000, [0x11111111, 0x22222222, 0x33333333, 0x44444444]);

        // MOVDQA XMM1, [EAX] (66 0F 6F 08)
        $this->executeMovdqa(0x6F, 0x08);

        $this->assertSame([0x11111111, 0x22222222, 0x33333333, 0x44444444], $this->cpuContext->getXmm(1));
    }

    public function testMovdqaStoreToMemoryAligned(): void
    {
        $this->setRegister(RegisterType::EAX, 0x5100, 32);
        $this->cpuContext->setXmm(1, [0xAAAAAAAA, 0xBBBBBBBB, 0xCCCCCCCC, 0xDDDDDDDD]);

        // MOVDQA [EAX], XMM1 (66 0F 7F 08)
        $this->executeMovdqa(0x7F, 0x08);

        $this->assertSame([0xAAAAAAAA, 0xBBBBBBBB, 0xCCCCCCCC, 0xDDDDDDDD], $this->readM128(0x5100));
    }

    public function testMovdqaUnalignedMemoryFaults(): void
    {
        $this->setRegister(RegisterType::EAX, 0x5204, 32);
        $this->writeM128(0x5204, [1, 2, 3, 4]);

        $this->expectException(FaultException::class);
        $this->executeMovdqa(0x6F, 0x08);
    }

    public function testMovdquLoadAllowsUnaligned(): void
    {
        $this->setRegister(RegisterType::EAX, 0x5304, 32);
        $this->writeM128(0x5304, [0xDEADBEEF, 0xCAFEBABE, 0x01234567, 0x89ABCDEF]);

        // MOVDQU XMM1, [EAX] (F3 0F 6F 08)
        $this->executeMovdqu(0x6F, 0x08);

        $this->assertSame([0xDEADBEEF, 0xCAFEBABE, 0x01234567, 0x89ABCDEF], $this->cpuContext->getXmm(1));
    }

    public function testMovdqaRegToRegUsesRexExtensionsIn64BitMode(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x05); // REX.R | REX.B

        $this->cpuContext->setXmm(9, [0x11111111, 0x22222222, 0x33333333, 0x44444444]);

        // MOVDQA XMM8, XMM9 (66 0F 6F C1) with REX.R/B
        $this->executeMovdqa(0x6F, 0xC1);

        $this->assertSame([0x11111111, 0x22222222, 0x33333333, 0x44444444], $this->cpuContext->getXmm(8));
    }

    private function executeMovdqa(int $secondByte, int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | ($secondByte & 0xFF);
        $this->movdqa->process($this->runtime, [0x66, $opcodeKey]);
    }

    private function executeMovdqu(int $secondByte, int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | ($secondByte & 0xFF);
        $this->movdqu->process($this->runtime, [0xF3, $opcodeKey]);
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

    /**
     * @return array{int,int,int,int}
     */
    private function readM128(int $address): array
    {
        return [
            $this->readMemory($address, 32) & 0xFFFFFFFF,
            $this->readMemory($address + 4, 32) & 0xFFFFFFFF,
            $this->readMemory($address + 8, 32) & 0xFFFFFFFF,
            $this->readMemory($address + 12, 32) & 0xFFFFFFFF,
        ];
    }
}

