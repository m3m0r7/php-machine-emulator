<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\RegisterType;

final class BiosInt13hUnrealModeSegmentBaseTest extends InstructionTestCase
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

    public function testChsReadUsesCachedSegmentBaseInRealMode(): void
    {
        // Simulate Unreal Mode: ES selector 0x10 with cached base 0.
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::ES, [
            'base' => 0x00000000,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);

        $this->setRegister(RegisterType::ES, 0x0010, 16);

        $buffer = 0x00200000;
        $this->setRegister(RegisterType::EBX, $buffer, 32);

        // INT 13h AH=02h (read sectors CHS), AL=01h (1 sector)
        $this->setRegister(RegisterType::EAX, 0x00000201, 32);
        // Cylinder 0, sector 1
        $this->setRegister(RegisterType::ECX, 0x00000001, 32);
        // Head 0, drive 0x80
        $this->setRegister(RegisterType::EDX, 0x00000080, 32);

        // Provide one hard drive in BDA (0x475).
        $this->writeMemory(0x475, 0x01, 8);

        $this->writeMemory($buffer + 0x50, 0xAA, 8);
        $this->writeMemory($buffer + 0x250, 0xAA, 8);

        $this->executeBytes([0xCD, 0x13]);

        // Correct: write starts at $buffer and overwrites +0x50, but not +0x250.
        $this->assertSame(0x00, $this->readMemory($buffer + 0x50, 8));
        $this->assertSame(0xAA, $this->readMemory($buffer + 0x250, 8));
        $this->assertFalse($this->getCarryFlag());
    }
}

