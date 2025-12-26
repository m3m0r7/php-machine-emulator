<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\Device\VideoContext;

/**
 * Minimal VBE smoke tests via INT 10h AX=4Fxx.
 *
 * These tests ensure VBE handlers do not crash and return the expected
 * success/failure codes for common GRUB probing paths.
 */
final class VbeTest extends InstructionTestCase
{
    private Int_ $int;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->int = new Int_($instructionList);

        $this->setRealMode16();
        $this->runtime->context()->devices()->register(new VideoContext());
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return $opcode === 0xCD ? $this->int : null;
    }

    public function testVbeGetInfoWritesVesaSignature(): void
    {
        $buffer = 0x3000;

        $this->setRegister(RegisterType::ES, 0x0000, 16);
        $this->setRegister(RegisterType::EDI, $buffer, 16);
        $this->setRegister(RegisterType::EAX, 0x4F00, 16);

        $this->executeBytes([0xCD, 0x10]);

        $this->assertSame(0x004F, $this->getRegister(RegisterType::EAX, 16));

        $sig = chr($this->readMemory($buffer + 0)) .
            chr($this->readMemory($buffer + 1)) .
            chr($this->readMemory($buffer + 2)) .
            chr($this->readMemory($buffer + 3));
        $this->assertSame('VESA', $sig);
        $this->assertSame(0x0300, $this->readMemory($buffer + 4, 16));
    }

    public function testVbeGetModeInfoFor141ReturnsSuccess(): void
    {
        $buffer = 0x3100;

        $this->setRegister(RegisterType::ES, 0x0000, 16);
        $this->setRegister(RegisterType::EDI, $buffer, 16);
        $this->setRegister(RegisterType::ECX, 0x0141, 16);
        $this->setRegister(RegisterType::EAX, 0x4F01, 16);

        $this->executeBytes([0xCD, 0x10]);

        $this->assertSame(0x004F, $this->getRegister(RegisterType::EAX, 16));
        $this->assertSame(0x009B, $this->readMemory($buffer + 0x00, 16));
        $this->assertSame(1024, $this->readMemory($buffer + 0x12, 16));
        $this->assertSame(768, $this->readMemory($buffer + 0x14, 16));
    }
}

