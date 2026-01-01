<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\RegisterType;

final class BiosInt13hExtendedDapLinearBufferTest extends InstructionTestCase
{
    private Int_ $int;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->int = new Int_($instructionList);

        $this->setRealMode32();
        $this->cpuContext->enableA20(true);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return $opcode === 0xCD ? $this->int : null;
    }

    public function testAh42UsesLinearBufferAddressWhenDapSizeIs18(): void
    {
        $dap = 0x1000;
        $buffer = 0x00200000;

        // Sentinel: should be overwritten by the read.
        $this->writeMemory($buffer + 0x10, 0xAA, 8);

        // DAP (size=0x18): sectorCount=1, bufferLinear=$buffer, lba=0.
        $this->writeMemory($dap + 0x00, 0x18, 8);
        $this->writeMemory($dap + 0x01, 0x00, 8);
        $this->writeMemory($dap + 0x02, 1, 16);
        $this->writeMemory($dap + 0x04, $buffer, 32);
        $this->writeMemory($dap + 0x08, 0, 32);
        $this->writeMemory($dap + 0x0C, 0, 32);

        $this->setRegister(RegisterType::DS, 0x0000, 16);
        $this->setRegister(RegisterType::ESI, $dap, 32);
        $this->setRegister(RegisterType::EDX, 0x00000080, 32); // DL=0x80
        $this->setRegister(RegisterType::EAX, 0x00004200, 32); // AH=0x42

        $this->executeBytes([0xCD, 0x13]);

        $this->assertFalse($this->getCarryFlag());
        $this->assertSame(0x00, $this->getRegister(RegisterType::EAX, 16) >> 8);
        $this->assertSame(1, $this->getRegister(RegisterType::EAX, 16) & 0xFF);

        $this->assertSame(0x00, $this->readMemory($buffer + 0x10, 8));
    }
}
