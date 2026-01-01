<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\MovFromCr;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\MovToCr;
use PHPMachineEmulator\Instruction\RegisterType;

final class MovControlRegistersLongMode64Test extends InstructionTestCase
{
    private MovToCr $movToCr;
    private MovFromCr $movFromCr;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->movToCr = new MovToCr($instructionList);
        $this->movFromCr = new MovFromCr($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    private function setRexBits(?int $wrxb): void
    {
        if ($wrxb === null) {
            $this->cpuContext->clearRex();
            return;
        }
        $this->cpuContext->setRex($wrxb & 0x0F);
    }

    private function executeMovToCr(int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);
        $this->movToCr->process($this->runtime, [0x0F22]);
    }

    private function executeMovFromCr(int $modrm): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr($modrm));
        $this->memoryStream->setOffset(0);
        $this->movFromCr->process($this->runtime, [0x0F20]);
    }

    public function testMovCr2RaxStoresFull64BitValue(): void
    {
        $this->setRexBits(null);

        $value = 0x0000123456789ABC;
        $this->setRegister(RegisterType::EAX, $value, 64);

        // ModR/M: 11 010 000 => MOV CR2, RAX
        $this->executeMovToCr(0xD0);

        $this->assertSame($value, $this->memoryAccessor->readControlRegister(2));
    }

    public function testMovR8Cr3UsesRexBForDestinationRegister(): void
    {
        $value = 0x0000FEDCBA987654;
        $this->memoryAccessor->writeControlRegister(3, $value);
        $this->setRegister(RegisterType::R8, 0, 64);

        // REX.B=1 selects R8 for r/m field.
        $this->setRexBits(0x1);

        // ModR/M: 11 011 000 => MOV r/m64, CR3 (r/m=RAX; with REX.B => R8)
        $this->executeMovFromCr(0xD8);

        $this->assertSame($value, $this->getRegister(RegisterType::R8, 64));
    }

    public function testMovCr8RaxUsesRexRToSelectCr8(): void
    {
        $value = 0x1111222233334444;
        $this->setRegister(RegisterType::EAX, $value, 64);

        // REX.R=1 extends the control register number: CR0 + 8 => CR8.
        $this->setRexBits(0x4);

        // ModR/M: 11 000 000 => MOV CR8, RAX (reg=0 + REX.R => 8)
        $this->executeMovToCr(0xC0);

        $this->assertSame($value, $this->memoryAccessor->readControlRegister(8));
    }

    public function testMovRaxCr8UsesRexRToSelectCr8(): void
    {
        $value = 0x5555666677778888;
        $this->memoryAccessor->writeControlRegister(8, $value);
        $this->setRegister(RegisterType::EAX, 0, 64);

        $this->setRexBits(0x4);

        // ModR/M: 11 000 000 => MOV RAX, CR8 (reg=0 + REX.R => 8)
        $this->executeMovFromCr(0xC0);

        $this->assertSame($value, $this->getRegister(RegisterType::EAX, 64));
    }

    public function testMovCr8R8UsesRexRAndRexB(): void
    {
        $value = 0x0102030405060708;
        $this->setRegister(RegisterType::R8, $value, 64);

        // REX.R=1 => CR8, REX.B=1 => r/m=R8.
        $this->setRexBits(0x5);

        // ModR/M: 11 000 000 => MOV CR8, R8
        $this->executeMovToCr(0xC0);

        $this->assertSame($value, $this->memoryAccessor->readControlRegister(8));
    }
}
