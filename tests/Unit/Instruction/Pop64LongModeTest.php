<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\Intel\x86_64 as X86_64InstructionList;
use PHPMachineEmulator\Instruction\Intel\x86_64\Pop64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

final class Pop64LongModeTest extends InstructionTestCase
{
    private Pop64 $pop64;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = new X86_64InstructionList();
        $this->pop64 = new Pop64($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return ($opcode >= 0x58 && $opcode <= 0x5F) ? $this->pop64 : null;
    }

    public function testPopRaxAdvancesRspAndWritesValue(): void
    {
        $sp = 0x3000;
        $value = 0x123456789ABCDEF0;

        $this->setRegister(RegisterType::ESP, $sp, 64); // RSP
        $this->writeMemory($sp, $value, 64);

        // 58: POP r64 (RAX)
        $this->executeBytes([0x58]);

        $this->assertSame('0x123456789abcdef0', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertSame($sp + 8, $this->getRegister(RegisterType::ESP, 64));
    }

    public function testPopWithRexBTargetsExtendedRegister(): void
    {
        $this->cpuContext->setRex(0x1); // REX.B

        $sp = 0x3100;
        $value = 0x1122334455667788;

        $this->setRegister(RegisterType::ESP, $sp, 64); // RSP
        $this->writeMemory($sp, $value, 64);
        $this->setRegister(RegisterType::R8, 0, 64);

        // 58: POP r64 (reg=0 -> R8 with REX.B)
        $this->executeBytes([0x58]);

        $this->assertSame('0x1122334455667788', UInt64::of($this->getRegister(RegisterType::R8, 64))->toHex());
        $this->assertSame($sp + 8, $this->getRegister(RegisterType::ESP, 64));
    }
}

