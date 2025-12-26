<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Group2;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

class Group2LongMode64Test extends InstructionTestCase
{
    private Group2 $group2;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->group2 = new Group2($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return in_array($opcode, [0xC0, 0xC1, 0xD0, 0xD1, 0xD2, 0xD3], true) ? $this->group2 : null;
    }

    private function enableRexW(): void
    {
        $this->cpuContext->setRex(0x8);
    }

    public function testShlRaxBy1SetsCfAndOf(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, UInt64::of('9223372036854775808')->toInt(), 64); // 0x8000000000000000
        $this->executeBytes([0xD1, 0xE0]); // SHL r/m64, 1 (modrm: 11 100 000)

        $this->assertSame('0x0000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
    }

    public function testShlRaxByClUses6BitMask(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 1, 64);
        $this->setRegister(RegisterType::ECX, 33, 64); // CL=33 (0x21), effective count should be 33 (mask 0x3F)

        $this->executeBytes([0xD3, 0xE0]); // SHL r/m64, CL

        $this->assertSame('0x0000000200000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertFalse($this->getCarryFlag());
    }

    public function testShrRaxBy1SetsOfFromOriginalMsb(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, UInt64::of('9223372036854775808')->toInt(), 64); // 0x8000000000000000
        $this->executeBytes([0xD1, 0xE8]); // SHR r/m64, 1 (modrm: 11 101 000)

        $this->assertSame('0x4000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
    }

    public function testSarRaxBy1ClearsOf(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, UInt64::of('9223372036854775808')->toInt(), 64); // 0x8000000000000000
        $this->executeBytes([0xD1, 0xF8]); // SAR r/m64, 1 (modrm: 11 111 000)

        $this->assertSame('0xc000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->getSignFlag());
    }

    public function testRolRaxBy1SetsCfAndOf(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, UInt64::of('9223372036854775809')->toInt(), 64); // 0x8000000000000001
        $this->executeBytes([0xD1, 0xC0]); // ROL r/m64, 1

        $this->assertSame('0x0000000000000003', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testRorRaxBy1SetsCfAndOf(): void
    {
        $this->enableRexW();

        $this->setRegister(RegisterType::EAX, 3, 64); // 0x0000000000000003
        $this->executeBytes([0xD1, 0xC8]); // ROR r/m64, 1

        $this->assertSame('0x8000000000000001', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testRclRaxBy1RotatesThroughCarry(): void
    {
        $this->enableRexW();

        $this->setCarryFlag(false);
        $this->setRegister(RegisterType::EAX, UInt64::of('9223372036854775808')->toInt(), 64); // 0x8000000000000000
        $this->executeBytes([0xD1, 0xD0]); // RCL r/m64, 1

        $this->assertSame('0x0000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testRcrRaxBy1RotatesThroughCarry(): void
    {
        $this->enableRexW();

        $this->setCarryFlag(false);
        $this->setRegister(RegisterType::EAX, 1, 64); // 0x0000000000000001
        $this->executeBytes([0xD1, 0xD8]); // RCR r/m64, 1

        $this->assertSame('0x0000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
    }
}
