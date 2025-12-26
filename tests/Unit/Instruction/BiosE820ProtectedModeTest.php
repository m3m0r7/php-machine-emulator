<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\RegisterType;

final class BiosE820ProtectedModeTest extends InstructionTestCase
{
    private Int_ $int;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->int = new Int_($instructionList);

        $this->setProtectedMode32();
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return $opcode === 0xCD ? $this->int : null;
    }

    public function testE820UsesProtectedModeSegmentBaseForEsDiBuffer(): void
    {
        $gdtBase = 0x0500;

        // Build a minimal GDT with a data segment at selector 0x10 (index 2) base=0x2000.
        // Descriptor bytes: limit=0xFFFF, base=0x2000, access=0x92, gran=0xCF.
        $dataDesc = [0xFF, 0xFF, 0x00, 0x20, 0x00, 0x92, 0xCF, 0x00];
        for ($i = 0; $i < 8; $i++) {
            // Null descriptor at index 0
            $this->writeMemory($gdtBase + $i, 0x00);
            // Descriptor at index 2 (offset 16)
            $this->writeMemory($gdtBase + 16 + $i, $dataDesc[$i]);
        }

        $this->cpuContext->setGdtr($gdtBase, 0x17);

        $bufferOffset = 0x3000;
        $expectedLinear = 0x2000 + $bufferOffset;

        $this->setRegister(RegisterType::ES, 0x0010, 16);
        $this->setRegister(RegisterType::EDI, $bufferOffset, 32);
        $this->setRegister(RegisterType::EBX, 0x00000000, 32);
        $this->setRegister(RegisterType::ECX, 20, 32);
        $this->setRegister(RegisterType::EDX, 0x534D4150, 32); // 'SMAP'
        $this->setRegister(RegisterType::EAX, 0x0000E820, 32);

        $this->executeBytes([0xCD, 0x15]);

        $this->assertFalse($this->getCarryFlag());
        $this->assertSame(0x534D4150, $this->getRegister(RegisterType::EAX, 32));

        // First E820 entry: base=0, length=0x9FC00, type=1
        $this->assertSame(0x00000000, $this->readMemory($expectedLinear + 0x00, 32));
        $this->assertSame(0x0009FC00, $this->readMemory($expectedLinear + 0x08, 32));
        $this->assertSame(0x00000001, $this->readMemory($expectedLinear + 0x10, 32));

        // Ensure we didn't mistakenly write to real-mode ES<<4 + DI.
        $wrongLinear = (0x0010 << 4) + ($bufferOffset & 0xFFFF);
        $this->assertSame(0x00000000, $this->readMemory($wrongLinear + 0x08, 32));
    }
}

