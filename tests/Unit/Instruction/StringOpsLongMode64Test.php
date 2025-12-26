<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Cmpsw;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsw;
use PHPMachineEmulator\Instruction\Intel\x86\Movsw;
use PHPMachineEmulator\Instruction\Intel\x86\Scasw;
use PHPMachineEmulator\Instruction\Intel\x86\Stosw;
use PHPMachineEmulator\Instruction\RegisterType;

final class StringOpsLongMode64Test extends InstructionTestCase
{
    private Movsw $movsw;
    private Stosw $stosw;
    private Lodsw $lodsw;
    private Scasw $scasw;
    private Cmpsw $cmpsw;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->movsw = new Movsw($instructionList);
        $this->stosw = new Stosw($instructionList);
        $this->lodsw = new Lodsw($instructionList);
        $this->scasw = new Scasw($instructionList);
        $this->cmpsw = new Cmpsw($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);

        $this->setRegister(RegisterType::DS, 0);
        $this->setRegister(RegisterType::ES, 0);
        $this->setDirectionFlag(false);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xA5 => $this->movsw,  // MOVSQ (REX.W=1)
            0xAB => $this->stosw,  // STOSQ (REX.W=1)
            0xAD => $this->lodsw,  // LODSQ (REX.W=1)
            0xAF => $this->scasw,  // SCASQ (REX.W=1)
            0xA7 => $this->cmpsw,  // CMPSQ (REX.W=1)
            default => null,
        };
    }

    private function enableRexW(): void
    {
        $this->cpuContext->setRex(0x8); // W=1
    }

    public function testMovsqMovesQwordAndIncrementsRsiRdiBy8(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::ESI, 0x1000, 64);
        $this->setRegister(RegisterType::EDI, 0x2000, 64);
        $this->writeMemory(0x1000, 0x1122334455667788, 64);

        $this->executeBytes([0xA5]);

        $this->assertSame(0x1122334455667788, $this->readMemory(0x2000, 64));
        $this->assertSame(0x1008, $this->getRegister(RegisterType::ESI, 64));
        $this->assertSame(0x2008, $this->getRegister(RegisterType::EDI, 64));
    }

    public function testStosqStoresRaxAndIncrementsRdiBy8(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 0x0123456789ABCDEF, 64);
        $this->setRegister(RegisterType::EDI, 0x3000, 64);
        $this->writeMemory(0x3000, 0xDEADBEEF, 32);

        $this->executeBytes([0xAB]);

        $this->assertSame(0x0123456789ABCDEF, $this->readMemory(0x3000, 64));
        $this->assertSame(0x3008, $this->getRegister(RegisterType::EDI, 64));
    }

    public function testLodsqLoadsQwordIntoRaxAndIncrementsRsiBy8(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::ESI, 0x4000, 64);
        $this->setRegister(RegisterType::EAX, 0, 64);
        $this->writeMemory(0x4000, 0x1122334455667788, 64);

        $this->executeBytes([0xAD]);

        $this->assertSame(0x1122334455667788, $this->getRegister(RegisterType::EAX, 64));
        $this->assertSame(0x4008, $this->getRegister(RegisterType::ESI, 64));
    }

    public function testScasqUsesUnsignedCompareForCarryFlag(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, -1, 64); // 0xffffffffffffffff
        $this->setRegister(RegisterType::EDI, 0x5000, 64);
        $this->writeMemory(0x5000, 0, 64);

        $this->executeBytes([0xAF]);

        $this->assertFalse($this->getCarryFlag()); // unsigned: 0xffff... >= 0
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertSame(0x5008, $this->getRegister(RegisterType::EDI, 64));
    }

    public function testScasqSetsAuxiliaryCarryFlagOnNibbleBorrow(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 0, 64);
        $this->setRegister(RegisterType::EDI, 0x5100, 64);
        $this->writeMemory(0x5100, 1, 64);

        $this->executeBytes([0xAF]);

        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->getSignFlag());
    }

    public function testCmpsqUsesUnsignedCompareForCarryFlag(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::ESI, 0x6000, 64);
        $this->setRegister(RegisterType::EDI, 0x7000, 64);
        $this->writeMemory(0x6000, 0, 64);
        $this->writeMemory(0x7000, -1, 64); // 0xffffffffffffffff

        $this->executeBytes([0xA7]);

        $this->assertTrue($this->getCarryFlag()); // unsigned: 0 < 0xffff...
        $this->assertFalse($this->getZeroFlag());
        $this->assertSame(0x6008, $this->getRegister(RegisterType::ESI, 64));
        $this->assertSame(0x7008, $this->getRegister(RegisterType::EDI, 64));
    }
}
