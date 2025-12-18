<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Call;
use PHPMachineEmulator\Instruction\Intel\x86\Group5;
use PHPMachineEmulator\Instruction\Intel\x86\Ret;
use PHPMachineEmulator\Instruction\RegisterType;

final class ControlFlowLongMode64Test extends InstructionTestCase
{
    private Call $call;
    private Ret $ret;
    private Group5 $group5;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->call = new Call($instructionList);
        $this->ret = new Ret($instructionList);
        $this->group5 = new Group5($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);

        $this->setRegister(RegisterType::CS, 0, 16);
        $this->setRegister(RegisterType::SS, 0, 16);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xE8 => $this->call,
            0xC3, 0xC2, 0xCB, 0xCA => $this->ret,
            0xFF => $this->group5,
            default => null,
        };
    }

    public function testCallRel32InLongModePushesRip64AndJumps(): void
    {
        $this->setRegister(RegisterType::ESP, 0x8000, 64); // RSP

        $this->executeBytes([0xE8, 0x10, 0x00, 0x00, 0x00]); // CALL +0x10

        $this->assertSame(0x7FF8, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(5, $this->readMemory(0x7FF8, 32));
        $this->assertSame(0, $this->readMemory(0x7FF8 + 4, 32));
        $this->assertSame(0x15, $this->memoryStream->offset());
    }

    public function testRetNearInLongModePopsRip64(): void
    {
        $this->setRegister(RegisterType::ESP, 0x7FF8, 64); // RSP
        $this->writeMemory(0x7FF8, 0x1234, 32);
        $this->writeMemory(0x7FF8 + 4, 0x00000000, 32);

        $this->executeBytes([0xC3]); // RET

        $this->assertSame(0x8000, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(0x1234, $this->memoryStream->offset());
    }

    public function testGroup5CallNearRm64PushesRip64AndSetsRip(): void
    {
        $this->setRegister(RegisterType::EAX, 0x4000, 64); // RAX target
        $this->setRegister(RegisterType::ESP, 0x8000, 64); // RSP

        $this->executeBytes([0xFF, 0xD0]); // CALL r/m64 (modrm: 11 010 000) => CALL RAX

        $this->assertSame(0x7FF8, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(2, $this->readMemory(0x7FF8, 32)); // return RIP (after opcode+modrm)
        $this->assertSame(0, $this->readMemory(0x7FF8 + 4, 32));
        $this->assertSame(0x4000, $this->memoryStream->offset());
    }

    public function testGroup5JmpNearRm64SetsRip(): void
    {
        $this->setRegister(RegisterType::EAX, 0x5000, 64); // RAX target

        $this->executeBytes([0xFF, 0xE0]); // JMP r/m64 (modrm: 11 100 000) => JMP RAX

        $this->assertSame(0x5000, $this->memoryStream->offset());
    }

    public function testGroup5PushRm64Pushes64ByDefault(): void
    {
        $this->setRegister(RegisterType::EAX, 0x1122334455667788, 64); // RAX
        $this->setRegister(RegisterType::ESP, 0x8000, 64); // RSP

        $this->executeBytes([0xFF, 0xF0]); // PUSH r/m (modrm: 11 110 000) => PUSH RAX

        $this->assertSame(0x7FF8, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(0x55667788, $this->readMemory(0x7FF8, 32));
        $this->assertSame(0x11223344, $this->readMemory(0x7FF8 + 4, 32));
    }

    public function testGroup5PushRmWith66PrefixPushes16(): void
    {
        $this->setRegister(RegisterType::EAX, 0x1122334455667788, 64); // RAX
        $this->setRegister(RegisterType::ESP, 0x8000, 64); // RSP

        // Layout in memory: 66 FF F0
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x66) . chr(0xFF) . chr(0xF0));

        // Simulate the decoder consuming the prefix and opcode bytes (offset now at ModR/M).
        $this->memoryStream->setOffset(2);
        $this->group5->process($this->runtime, [0x66, 0xFF]);

        $this->assertSame(0x7FFE, $this->getRegister(RegisterType::ESP, 64));
        $this->assertSame(0x7788, $this->readMemory(0x7FFE, 16));
    }
}

