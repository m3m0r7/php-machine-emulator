<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\Intel\x86_64 as X86_64InstructionList;
use PHPMachineEmulator\Instruction\Intel\x86_64\Arithmetic64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

class Arithmetic64FlagsTest extends InstructionTestCase
{
    private Arithmetic64 $arith;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);

        $instructionList = new X86_64InstructionList();
        $instructionList->setRuntime($this->runtime);
        $this->arith = new Arithmetic64($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return in_array($opcode, $this->arith->opcodes(), true) ? $this->arith : null;
    }

    private function enableRexW(): void
    {
        // REX.W=1 (lower 4 bits: 1000b). Presence matters even if other bits are 0.
        $this->cpuContext->setRex(0x8);
    }

    public function testAddRaxRbxSetsFlagsAndAvoidsPhpIntOverflow(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 0x7FFFFFFFFFFFFFFF, 64);
        $this->setRegister(RegisterType::EBX, 1, 64);

        // ADD r/m64, r64 (0x01 /r): modrm 11 011 000 = 0xD8 (dst=RAX, src=RBX)
        $this->executeBytes([0x01, 0xD8]);

        $this->assertSame('0x8000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag()); // low byte 0x00 has even parity
    }

    public function testSubRaxRbxSetsFlags(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 0, 64);
        $this->setRegister(RegisterType::EBX, 1, 64);

        // SUB r/m64, r64 (0x29 /r): modrm 11 011 000 = 0xD8 (dst=RAX, src=RBX)
        $this->executeBytes([0x29, 0xD8]);

        $this->assertSame('0xffffffffffffffff', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag()); // borrow
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag()); // low byte 0xFF has even parity
    }

    public function testCmpDoesNotWriteDestinationButSetsFlags(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 1, 64);
        $this->setRegister(RegisterType::EBX, 2, 64);

        // CMP r/m64, r64 (0x39 /r): modrm 11 011 000 = 0xD8 (cmp RAX, RBX)
        $this->executeBytes([0x39, 0xD8]);

        $this->assertSame('0x0000000000000001', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag()); // 1 < 2
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag()); // 1-2 => -1
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
    }

    public function testAddRm64RegWithMemoryOperandUpdatesMemory(): void
    {
        $this->enableRexW();

        $address = 0x1000;
        $this->setRegister(RegisterType::EAX, $address, 64); // RAX
        $this->setRegister(RegisterType::EBX, 5, 64); // RBX
        $this->writeMemory($address, 10, 64);

        // ADD r/m64, r64 (0x01 /r): modrm 00 011 000 = 0x18 (dst=[RAX], src=RBX)
        $this->executeBytes([0x01, 0x18]);

        $this->assertSame(15, $this->readMemory($address, 64));
    }
}
