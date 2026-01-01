<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\Device\VideoContext;

final class VbeUnrealModeSegmentBaseTest extends InstructionTestCase
{
    private Int_ $int;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $instructionList->method('register')->willReturn(new Register());

        $this->int = new Int_($instructionList);

        $this->setRealMode32();
        $this->cpuContext->enableA20(true);
        $this->runtime->context()->devices()->register(new VideoContext());
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return $opcode === 0xCD ? $this->int : null;
    }

    public function testVbeGetInfoUsesCachedEsBaseInRealMode(): void
    {
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::ES, [
            'base' => 0x00000000,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);
        $this->setRegister(RegisterType::ES, 0x0010, 16);

        $buffer = 0x00200000;
        $this->setRegister(RegisterType::EDI, $buffer, 32);
        $this->setRegister(RegisterType::EAX, 0x00004F00, 32);

        $this->executeBytes([0xCD, 0x10]);

        $this->assertSame(0x004F, $this->getRegister(RegisterType::EAX, 16));

        $sig = chr($this->readMemory($buffer + 0)) .
            chr($this->readMemory($buffer + 1)) .
            chr($this->readMemory($buffer + 2)) .
            chr($this->readMemory($buffer + 3));
        $this->assertSame('VESA', $sig);
    }
}
