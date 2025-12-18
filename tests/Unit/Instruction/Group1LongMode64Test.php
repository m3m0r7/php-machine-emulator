<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Group1;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

class Group1LongMode64Test extends InstructionTestCase
{
    private Group1 $group1;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->group1 = new Group1($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return in_array($opcode, [0x80, 0x81, 0x82, 0x83], true) ? $this->group1 : null;
    }

    private function enableRexW(): void
    {
        $this->cpuContext->setRex(0x8);
    }

    public function testSubRaxImm8SignExtendedSetsFlags(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 0, 64);
        $this->executeBytes([0x83, 0xE8, 0x01]); // SUB r/m64, imm8 (modrm: 11 101 000)

        $this->assertSame('0xffffffffffffffff', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
    }

    public function testOrRaxImm32SignExtendsTo64(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 0, 64);
        $this->executeBytes([0x81, 0xC8, 0x00, 0x00, 0x00, 0x80]); // OR r/m64, imm32 (imm32=0x80000000)

        $this->assertSame('0xffffffff80000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag()); // low byte 0x00 has even parity
    }

    public function testAdcRaxImm8UsesCarryInAndSetsCarryOut(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, UInt64::of('18446744073709551615')->toInt(), 64); // 0xffffffffffffffff
        $this->setCarryFlag(true);
        $this->executeBytes([0x83, 0xD0, 0x00]); // ADC r/m64, imm8 (modrm: 11 010 000)

        $this->assertSame('0x0000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
    }

    public function testSbbRaxImm8UsesBorrowIn(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 0, 64);
        $this->setCarryFlag(true);
        $this->executeBytes([0x83, 0xD8, 0x00]); // SBB r/m64, imm8 (modrm: 11 011 000)

        $this->assertSame('0xffffffffffffffff', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
    }
}

