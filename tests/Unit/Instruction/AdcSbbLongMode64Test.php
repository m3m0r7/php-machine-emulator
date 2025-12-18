<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\AdcRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\SbbRegRm;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

class AdcSbbLongMode64Test extends InstructionTestCase
{
    private AdcRegRm $adc;
    private SbbRegRm $sbb;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->adc = new AdcRegRm($instructionList);
        $this->sbb = new SbbRegRm($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match (true) {
            in_array($opcode, [0x10, 0x11, 0x12, 0x13], true) => $this->adc,
            in_array($opcode, [0x18, 0x19, 0x1A, 0x1B], true) => $this->sbb,
            default => null,
        };
    }

    private function enableRexW(): void
    {
        $this->cpuContext->setRex(0x8);
    }

    public function testAdcRaxRbxWithCarryInWrapsAndSetsCarryOut(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, UInt64::of('18446744073709551615')->toInt(), 64); // 0xffffffffffffffff
        $this->setRegister(RegisterType::EBX, 0, 64);
        $this->setCarryFlag(true);

        // ADC r/m64, r64 (0x11 /r): modrm 11 011 000 = 0xD8 (dst=RAX, src=RBX)
        $this->executeBytes([0x11, 0xD8]);

        $this->assertSame('0x0000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
    }

    public function testSbbRaxRbxWithBorrowInUnderflows(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 0, 64);
        $this->setRegister(RegisterType::EBX, 0, 64);
        $this->setCarryFlag(true); // borrow-in

        // SBB r/m64, r64 (0x19 /r): modrm 11 011 000 = 0xD8 (dst=RAX, src=RBX)
        $this->executeBytes([0x19, 0xD8]);

        $this->assertSame('0xffffffffffffffff', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
    }
}

