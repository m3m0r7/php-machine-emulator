<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Movaps;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Movups;
use PHPMachineEmulator\Instruction\RegisterType;

class MovapsMovupsTest extends TwoByteOpTestCase
{
    private Movaps $movaps;
    private Movups $movups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->movaps = new Movaps($this->instructionList);
        $this->movups = new Movups($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->movaps;
    }

    public function testMovapsLoadFromMemoryAligned(): void
    {
        $this->setRegister(RegisterType::EAX, 0x6000, 32);
        $this->writeM128(0x6000, [0x11111111, 0x22222222, 0x33333333, 0x44444444]);

        // MOVAPS XMM1, [EAX] (0F 28 08)
        $this->executeMovaps(0x28, 0x08);

        $this->assertSame([0x11111111, 0x22222222, 0x33333333, 0x44444444], $this->cpuContext->getXmm(1));
    }

    public function testMovapsStoreToMemoryAligned(): void
    {
        $this->setRegister(RegisterType::EAX, 0x6100, 32);
        $this->cpuContext->setXmm(1, [0xAAAAAAAA, 0xBBBBBBBB, 0xCCCCCCCC, 0xDDDDDDDD]);

        // MOVAPS [EAX], XMM1 (0F 29 08)
        $this->executeMovaps(0x29, 0x08);

        $this->assertSame([0xAAAAAAAA, 0xBBBBBBBB, 0xCCCCCCCC, 0xDDDDDDDD], $this->readM128(0x6100));
    }

    public function testMovapsUnalignedMemoryFaults(): void
    {
        $this->setRegister(RegisterType::EAX, 0x6204, 32);
        $this->writeM128(0x6204, [1, 2, 3, 4]);

        $this->expectException(FaultException::class);
        $this->executeMovaps(0x28, 0x08);
    }

    public function testMovupsLoadAllowsUnaligned(): void
    {
        $this->setRegister(RegisterType::EAX, 0x6304, 32);
        $this->writeM128(0x6304, [0xDEADBEEF, 0xCAFEBABE, 0x01234567, 0x89ABCDEF]);

        // MOVUPS XMM1, [EAX] (0F 10 08)
        $this->executeMovups(0x10, 0x08);

        $this->assertSame([0xDEADBEEF, 0xCAFEBABE, 0x01234567, 0x89ABCDEF], $this->cpuContext->getXmm(1));
    }

    public function testMovapsRegToRegUsesRexExtensionsIn64BitMode(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x05); // REX.R | REX.B

        $this->cpuContext->setXmm(9, [0x11111111, 0x22222222, 0x33333333, 0x44444444]);

        // MOVAPS XMM8, XMM9 (0F 28 C1) with REX.R/B
        $this->executeMovaps(0x28, 0xC1);

        $this->assertSame([0x11111111, 0x22222222, 0x33333333, 0x44444444], $this->cpuContext->getXmm(8));
    }

    private function executeMovaps(int $secondByte, int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | ($secondByte & 0xFF);
        $this->movaps->process($this->runtime, [$opcodeKey]);
    }

    private function executeMovups(int $secondByte, int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | ($secondByte & 0xFF);
        $this->movups->process($this->runtime, [$opcodeKey]);
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
