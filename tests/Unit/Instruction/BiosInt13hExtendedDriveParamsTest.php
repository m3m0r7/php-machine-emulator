<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\RegisterType;

final class BiosInt13hExtendedDriveParamsTest extends InstructionTestCase
{
    private Int_ $int;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->int = new Int_($instructionList);

        $this->setRealMode16();
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return $opcode === 0xCD ? $this->int : null;
    }

    public function testInt13hAh48WritesBytesPerSectorAtStandardOffset(): void
    {
        $buffer = 0x2000;

        // DS:SI points to result buffer
        $this->setRegister(RegisterType::DS, 0x0000, 16);
        $this->setRegister(RegisterType::ESI, $buffer, 16);
        // Caller-provided buffer size (at least 0x1A)
        $this->writeMemory($buffer, 0x001A, 16);

        // DL = first hard disk
        $this->setRegister(RegisterType::EDX, 0x0080, 16);
        // AH = 0x48 (Get Extended Drive Parameters)
        $this->setRegister(RegisterType::EAX, 0x4800, 16);

        $this->executeBytes([0xCD, 0x13]);

        $this->assertFalse($this->getCarryFlag());
        $this->assertSame(0x00, ($this->getRegister(RegisterType::EAX, 16) >> 8) & 0xFF);

        $this->assertSame(0x001A, $this->readMemory($buffer, 16));
        $this->assertSame(512, $this->readMemory($buffer + 0x18, 16));

        // Sanity-check a few of the 32-bit geometry fields.
        $this->assertSame(1024, $this->readMemory($buffer + 0x04, 32));
        $this->assertSame(16, $this->readMemory($buffer + 0x08, 32));
        $this->assertSame(63, $this->readMemory($buffer + 0x0C, 32));
    }
}

