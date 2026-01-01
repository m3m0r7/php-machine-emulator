<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TestImmAl;
use PHPMachineEmulator\Instruction\Intel\x86\TestRegRm;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

class TestLongMode64Test extends InstructionTestCase
{
    private TestRegRm $testRegRm;
    private TestImmAl $testImm;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->testRegRm = new TestRegRm($instructionList);
        $this->testImm = new TestImmAl($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0x84, 0x85 => $this->testRegRm,
            0xA8, 0xA9 => $this->testImm,
            default => null,
        };
    }

    private function enableRexW(): void
    {
        $this->cpuContext->setRex(0x8);
    }

    public function testTestRaxRbxSetsSignFlag(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, UInt64::of('9223372036854775808')->toInt(), 64); // 0x8000000000000000
        $this->setRegister(RegisterType::EBX, UInt64::of('9223372036854775808')->toInt(), 64);

        // TEST r/m64, r64 (0x85 /r): modrm 11 011 000 = 0xD8 (RAX & RBX)
        $this->executeBytes([0x85, 0xD8]);

        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    public function testTestRaxImm32SignExtendsIn64BitMode(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, UInt64::of('18446744069414584320')->toInt(), 64); // 0xffffffff00000000

        // TEST RAX, imm32 (0xA9): imm32 = 0x80000000, sign-extended -> 0xffffffff80000000
        $this->executeBytes([0xA9, 0x00, 0x00, 0x00, 0x80]);

        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }
}
