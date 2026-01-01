<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\PopReg;
use PHPMachineEmulator\Instruction\Intel\x86\PopRm;
use PHPMachineEmulator\Instruction\Intel\x86\Popf;
use PHPMachineEmulator\Instruction\Intel\x86\PushImm;
use PHPMachineEmulator\Instruction\Intel\x86\PushReg;
use PHPMachineEmulator\Instruction\Intel\x86\Pushf;
use PHPMachineEmulator\Instruction\RegisterType;

final class PushPopLongMode64Test extends InstructionTestCase
{
    private PushReg $pushReg;
    private PopReg $popReg;
    private PushImm $pushImm;
    private Pushf $pushf;
    private Popf $popf;
    private PopRm $popRm;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->pushReg = new PushReg($instructionList);
        $this->popReg = new PopReg($instructionList);
        $this->pushImm = new PushImm($instructionList);
        $this->pushf = new Pushf($instructionList);
        $this->popf = new Popf($instructionList);
        $this->popRm = new PopRm($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match (true) {
            $opcode >= 0x50 && $opcode <= 0x57 => $this->pushReg,
            $opcode >= 0x58 && $opcode <= 0x5F => $this->popReg,
            $opcode === 0x68 || $opcode === 0x6A => $this->pushImm,
            $opcode === 0x9C => $this->pushf,
            $opcode === 0x9D => $this->popf,
            $opcode === 0x8F => $this->popRm,
            default => null,
        };
    }

    public function testPushPopRegUses64BitInLongMode(): void
    {
        $rsp = 0x8000;
        $value = 0x1122334455667788;

        $this->setRegister(RegisterType::ESP, $rsp, 64);
        $this->setRegister(RegisterType::EAX, $value, 64);

        $this->executeBytes([0x50]); // PUSH RAX
        $this->assertSame($rsp - 8, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame($value, $this->readMemory($rsp - 8, 64));

        $this->executeBytes([0x58]); // POP RAX
        $this->assertSame($rsp, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame($value, $this->getRegister(RegisterType::EAX, 64));
    }

    public function testPushRspPushesOldRspInLongMode(): void
    {
        $rsp = 0x8000;
        $this->setRegister(RegisterType::ESP, $rsp, 64);

        $this->executeBytes([0x54]); // PUSH RSP

        $this->assertSame($rsp - 8, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame($rsp, $this->readMemory($rsp - 8, 64));
    }

    public function testPushImm8SignExtendsAndUses64BitInLongMode(): void
    {
        $rsp = 0x8000;
        $this->setRegister(RegisterType::ESP, $rsp, 64);

        $this->executeBytes([0x6A, 0xFF]); // PUSH imm8 (-1)

        $this->assertSame($rsp - 8, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(-1, $this->readMemory($rsp - 8, 64));
    }

    public function testPushImm32SignExtendsAndUses64BitInLongMode(): void
    {
        $rsp = 0x8000;
        $this->setRegister(RegisterType::ESP, $rsp, 64);

        // imm32 = 0x80000000 (sign-extended to 0xFFFFFFFF80000000).
        $this->executeBytes([0x68, 0x00, 0x00, 0x00, 0x80]);

        $this->assertSame($rsp - 8, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(-2147483648, $this->readMemory($rsp - 8, 64));
    }

    public function testPushfqPopfqUse64BitFrameInLongMode(): void
    {
        $rsp = 0x8000;
        $this->setRegister(RegisterType::ESP, $rsp, 64);

        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->setInterruptFlag(true);

        $this->executeBytes([0x9C]); // PUSHFQ
        $this->assertSame($rsp - 8, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(0x243, $this->readMemory($rsp - 8, 64) & 0xFFFF);

        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->setInterruptFlag(false);

        $this->executeBytes([0x9D]); // POPFQ
        $this->assertSame($rsp, $this->getRegister(RegisterType::ESP, 64));
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertTrue($this->getInterruptFlag());
    }

    public function testPushPopWithRexBSelectsExtendedRegister(): void
    {
        // Simulate the effect of a preceding REX prefix with B=1 (e.g., 0x41).
        $this->cpuContext->setRex(0x1);

        $rsp = 0x8000;
        $value = 0x0102030405060708;

        $this->setRegister(RegisterType::ESP, $rsp, 64);
        $this->setRegister(RegisterType::R8, $value, 64);

        $this->executeBytes([0x50]); // PUSH R8 (0x41 0x50 in machine code)
        $this->assertSame($rsp - 8, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame($value, $this->readMemory($rsp - 8, 64));

        $this->executeBytes([0x58]); // POP R8 (0x41 0x58 in machine code)
        $this->assertSame($rsp, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame($value, $this->getRegister(RegisterType::R8, 64));
    }

    public function testPopRm64WritesQwordToMemoryInLongMode(): void
    {
        $rsp = 0x8000;
        $value = 0x1122334455667788;
        $destBase = 0x9000;

        $this->setRegister(RegisterType::ESP, $rsp, 64);
        $this->setRegister(RegisterType::EAX, $value, 64);

        // PUSH RAX (value onto stack).
        $this->executeBytes([0x50]);

        $this->setRegister(RegisterType::EBP, $destBase, 64);

        // POP qword ptr [RBP+0x10] : 8F /0, ModRM 01 000 101 = 0x45, disp8=0x10.
        $this->executeBytes([0x8F, 0x45, 0x10]);

        $this->assertSame($rsp, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame($value, $this->readMemory($destBase + 0x10, 64));
    }
}
