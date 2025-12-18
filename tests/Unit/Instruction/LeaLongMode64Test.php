<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Lea;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

final class LeaLongMode64Test extends InstructionTestCase
{
    private Lea $lea;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());
        $this->lea = new Lea($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return $opcode === 0x8D ? $this->lea : null;
    }

    public function testLeaR8RipRelativeWritesFull64BitResult(): void
    {
        // REX.W + REX.R: LEA r64, m with destination in extended register set (r8)
        $this->cpuContext->setRex(0xC); // 1100b = REX.W | REX.R

        $this->setRegister(RegisterType::EAX, 0x11111111, 64); // RAX (must remain unchanged)
        $this->setRegister(RegisterType::R8, 0x22222222, 64);

        // LEA r8, [RIP + 0x00010000]
        // opcode 8D, ModRM 00 000 101 = 0x05 (reg=0 -> r8 via REX.R, rm=RIP-relative)
        // disp32 = 0x00010000; RIP for calculation is next instruction (offset 6)
        $this->executeBytes([0x8D, 0x05, 0x00, 0x00, 0x01, 0x00]);

        $this->assertSame('0x0000000000010006', UInt64::of($this->getRegister(RegisterType::R8, 64))->toHex());
        $this->assertSame('0x0000000011111111', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
    }

    public function testLeaR9dRipRelativeZeroExtendsAndUsesRexR(): void
    {
        // REX.R only: LEA r32, m with destination in extended register set (r9d)
        $this->cpuContext->setRex(0x4); // 0100b = REX.R

        $this->setRegister(RegisterType::ECX, 0x33333333, 64); // RCX (must remain unchanged)
        $this->setRegister(RegisterType::R9, UInt64::of('18446744073709551615')->toInt(), 64); // 0xffffffffffffffff

        // LEA r9d, [RIP + 0x12345678]
        // ModRM 00 001 101 = 0x0D (reg=1 -> r9 via REX.R, rm=RIP-relative)
        // RIP for calculation is next instruction (offset 6), so result = 0x1234567E
        $this->executeBytes([0x8D, 0x0D, 0x78, 0x56, 0x34, 0x12]);

        $this->assertSame('0x000000001234567e', UInt64::of($this->getRegister(RegisterType::R9, 64))->toHex());
        $this->assertSame('0x0000000033333333', UInt64::of($this->getRegister(RegisterType::ECX, 64))->toHex());
    }
}

