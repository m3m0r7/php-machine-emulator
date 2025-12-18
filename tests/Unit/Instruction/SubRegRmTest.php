<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\SubRegRm;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for SUB r/m, r and SUB r, r/m instructions
 * Opcodes: 0x28, 0x29, 0x2A, 0x2B
 */
class SubRegRmTest extends InstructionTestCase
{
    private SubRegRm $subRegRm;

    protected function setUp(): void
    {
        parent::setUp();
        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->subRegRm = new SubRegRm($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return in_array($opcode, [0x28, 0x29, 0x2A, 0x2B], true) ? $this->subRegRm : null;
    }

    public function testSubSetsAuxiliaryCarryFlagOnNibbleBorrow32(): void
    {
        // SUB EAX, ECX (0x29 /r, ModRM=0xC8 = 11 001 000)
        // 0x10 - 0x01 = 0x0F (borrow from bit 4 -> AF=1)
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->setRegister(RegisterType::ECX, 0x00000001);
        $this->executeBytes([0x29, 0xC8]); // SUB EAX, ECX

        $this->assertSame(0x0000000F, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    public function testSubClearsAuxiliaryCarryFlagWhenNoNibbleBorrow32(): void
    {
        // 0x11 - 0x01 = 0x10 (no borrow from bit 4 -> AF=0)
        $this->memoryAccessor->setAuxiliaryCarryFlag(true);
        $this->setRegister(RegisterType::EAX, 0x00000011);
        $this->setRegister(RegisterType::ECX, 0x00000001);
        $this->executeBytes([0x29, 0xC8]); // SUB EAX, ECX

        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    public function testSubSetsAuxiliaryCarryFlagOnNibbleBorrow8(): void
    {
        // SUB AL, CL (0x28 /r, ModRM=0xC8)
        // 0x10 - 0x01 = 0x0F (borrow from bit 4 -> AF=1)
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->setRegister(RegisterType::ECX, 0x00000001);
        $this->executeBytes([0x28, 0xC8]); // SUB AL, CL

        $this->assertSame(0x0F, $this->getRegister(RegisterType::EAX, 8));
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }
}

