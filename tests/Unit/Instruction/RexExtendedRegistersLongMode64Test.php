<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\Intel\x86_64 as X86_64InstructionList;
use PHPMachineEmulator\Instruction\Intel\x86_64\Arithmetic64;
use PHPMachineEmulator\Instruction\Intel\x86_64\Mov64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

final class RexExtendedRegistersLongMode64Test extends InstructionTestCase
{
    private Mov64 $mov64;
    private Arithmetic64 $arith64;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = new X86_64InstructionList();
        $this->mov64 = new Mov64($instructionList);
        $this->arith64 = new Arithmetic64($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0x89, 0x8B, 0xB8, 0xB9, 0xBA, 0xBB, 0xBC, 0xBD, 0xBE, 0xBF, 0xC7 => $this->mov64,
            0x01, 0x03, 0x05, 0x29, 0x2B, 0x2D, 0x39, 0x3B, 0x3D, 0x21, 0x23, 0x25, 0x09, 0x0B, 0x0D, 0x31, 0x33, 0x35 => $this->arith64,
            default => null,
        };
    }

    private function setRexBits(int $wrxb): void
    {
        $this->cpuContext->setRex($wrxb & 0x0F);
    }

    public function testMovR8dR9dReadsExtendedRmAndZeroExtends(): void
    {
        // MOV r32, r/m32: needs REX.R for r8d and REX.B for r9d (REX.W=0)
        $this->setRexBits(0x5); // 0101b = REX.R | REX.B

        $this->setRegister(RegisterType::R9, 0x12345678, 64);
        $this->setRegister(RegisterType::R8, UInt64::of('18446744073709551615')->toInt(), 64); // 0xffffffffffffffff

        // 8B /r : MOV r32, r/m32
        // ModRM 11 000 001 = 0xC1 (reg=0 -> r8d via REX.R, rm=1 -> r9d via REX.B)
        $this->executeBytes([0x8B, 0xC1]);

        $this->assertSame('0x0000000012345678', UInt64::of($this->getRegister(RegisterType::R8, 64))->toHex());
        $this->assertSame('0x0000000012345678', UInt64::of($this->getRegister(RegisterType::R9, 64))->toHex());
    }

    public function testAddR8dR9dReadsExtendedRmAndZeroExtendsResult(): void
    {
        // ADD r32, r/m32: needs REX.R for r8d and REX.B for r9d (REX.W=0)
        $this->setRexBits(0x5); // 0101b = REX.R | REX.B

        $this->setRegister(RegisterType::R8, UInt64::of('18446744069414584321')->toInt(), 64); // 0xffffffff00000001 (r8d=1)
        $this->setRegister(RegisterType::R9, 2, 64);

        // 03 /r : ADD r32, r/m32
        // ModRM 11 000 001 = 0xC1 (reg=0 -> r8d via REX.R, rm=1 -> r9d via REX.B)
        $this->executeBytes([0x03, 0xC1]);

        $this->assertSame('0x0000000000000003', UInt64::of($this->getRegister(RegisterType::R8, 64))->toHex());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
    }

    public function testMovRm64Imm32InLongModeWritesSignExtendedImmediateToMemory(): void
    {
        $this->setRexBits(0x8); // REX.W

        $address = 0x2000;
        $this->setRegister(RegisterType::EAX, $address, 64); // RAX
        $this->writeMemory($address, 0, 64);

        // C7 /0: MOV r/m64, imm32 (sign-extended)
        // ModRM 00 000 000 = 0x00 -> [RAX]
        $this->executeBytes([0xC7, 0x00, 0xFF, 0xFF, 0xFF, 0xFF]);

        $this->assertSame('0xffffffffffffffff', UInt64::of($this->readMemory($address, 64))->toHex());
    }

    public function testMovRaxImm64InLongModeReadsImm64(): void
    {
        $this->setRexBits(0x8); // REX.W

        // B8: MOV r64, imm64 (RAX)
        $this->executeBytes([0xB8, 0xF0, 0xDE, 0xBC, 0x9A, 0x78, 0x56, 0x34, 0x12]);

        $this->assertSame('0x123456789abcdef0', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
    }

    public function testAddRaxImm32InLongModeSignExtendsImmediate(): void
    {
        $this->setRexBits(0x8); // REX.W

        $this->setRegister(RegisterType::EAX, 1, 64); // RAX=1

        // 05: ADD RAX, imm32 (sign-extended with REX.W)
        $this->executeBytes([0x05, 0xFF, 0xFF, 0xFF, 0xFF]); // +(-1)

        $this->assertSame('0x0000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
    }
}
