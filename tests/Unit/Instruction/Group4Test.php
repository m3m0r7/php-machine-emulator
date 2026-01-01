<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Group4;
use PHPMachineEmulator\Instruction\RegisterType;

final class Group4Test extends InstructionTestCase
{
    private Group4 $group4;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());
        $this->group4 = new Group4($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return $opcode === 0xFE ? $this->group4 : null;
    }

    public function testIncRm8PreservesCarryAndSetsAuxiliaryCarry(): void
    {
        $this->setProtectedMode32();
        $this->setCarryFlag(true);

        $this->setRegister(RegisterType::EAX, 0x0000000F, 32); // AL=0x0F

        // FE /0, ModR/M: 11 000 000 => INC AL
        $this->executeBytes([0xFE, 0xC0]);

        $this->assertSame(0x10, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
    }

    public function testDecRm8PreservesCarryAndSetsAuxiliaryCarry(): void
    {
        $this->setProtectedMode32();
        $this->setCarryFlag(true);

        $this->setRegister(RegisterType::EAX, 0x00000010, 32); // AL=0x10

        // FE /1, ModR/M: 11 001 000 => DEC AL
        $this->executeBytes([0xFE, 0xC8]);

        $this->assertSame(0x0F, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
    }

    public function testIncRm8SetsOverflowWhenCrossingSign(): void
    {
        $this->setProtectedMode32();
        $this->setCarryFlag(false);

        $this->setRegister(RegisterType::EAX, 0x0000007F, 32); // AL=0x7F

        // INC AL
        $this->executeBytes([0xFE, 0xC0]);

        $this->assertSame(0x80, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
    }

    public function testDecRm8SetsOverflowWhenCrossingSign(): void
    {
        $this->setProtectedMode32();
        $this->setCarryFlag(false);

        $this->setRegister(RegisterType::EAX, 0x00000080, 32); // AL=0x80

        // DEC AL
        $this->executeBytes([0xFE, 0xC8]);

        $this->assertSame(0x7F, $this->getRegister(RegisterType::EAX, 32) & 0xFF);
        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
    }

    public function testIncBytePtrDisp32IncrementsMemory(): void
    {
        $this->setProtectedMode32();

        $addr = 0x2345;
        $this->writeMemory($addr, 0xFF, 8);

        // FE /0, ModR/M: 00 000 101 => INC byte ptr [disp32]
        $this->executeBytes([0xFE, 0x05, 0x45, 0x23, 0x00, 0x00]);

        $this->assertSame(0x00, $this->readMemory($addr, 8));
        $this->assertTrue($this->getZeroFlag());
    }

    public function testIncSplUsesRexPrefixAndDoesNotTouchAh(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);

        // Mark REX prefix present (no WRXB bits), so rm=4 refers to SPL, not AH.
        $this->cpuContext->setRex(0x0);

        $this->setRegister(RegisterType::ESP, 0x0000000000000001, 64); // RSP=1 (SPL=1)
        $this->setRegister(RegisterType::EAX, 0x0000000000001200, 64); // AH=0x12

        // FE /0, ModR/M: 11 000 100 => INC r/m8 (rm=4 => SPL when REX present)
        $this->executeBytes([0xFE, 0xC4]);

        $this->assertSame(0x0000000000000002, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(0x0000000000001200, $this->getRegister(RegisterType::EAX, 64));
    }

    public function testIncR8bUsesRexBExtension(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);

        $this->cpuContext->setRex(0x1); // REX.B

        $this->setRegister(RegisterType::R8, 0x00000000000000FF, 64); // R8B=0xFF

        // FE /0, ModR/M: 11 000 000 => INC r/m8 (rm=0 => R8B when REX.B=1)
        $this->executeBytes([0xFE, 0xC0]);

        $this->assertSame(0x0000000000000000, $this->getRegister(RegisterType::R8, 64));
        $this->assertTrue($this->getZeroFlag());
    }
}
