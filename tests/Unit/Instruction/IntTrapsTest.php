<?php
declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Int1;
use PHPMachineEmulator\Instruction\Intel\x86\Int3;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\Intel\x86\Into;

final class IntTrapsTest extends InstructionTestCase
{
    private InstructionListInterface $instructionList;
    private Int1 $int1;
    private Int3 $int3;
    private Into $into;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instructionList = $this->createMock(InstructionListInterface::class);
        $this->int1 = new Int1($this->instructionList);
        $this->int3 = new Int3($this->instructionList);
        $this->into = new Into($this->instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xF1 => $this->int1,
            0xCC => $this->int3,
            0xCE => $this->into,
            default => null,
        };
    }

    public function testInt3RaisesBreakpointViaIntHandler(): void
    {
        $handler = $this->createMock(Int_::class);
        $handler
            ->expects($this->once())
            ->method('raiseSoftware')
            ->with($this->runtime, 3, 1, null);

        $this->instructionList
            ->expects($this->once())
            ->method('findInstruction')
            ->with(0xCD)
            ->willReturn($handler);

        $this->executeBytes([0xCC]);
    }

    public function testInt1RaisesDebugExceptionViaIntHandler(): void
    {
        $handler = $this->createMock(Int_::class);
        $handler
            ->expects($this->once())
            ->method('raiseSoftware')
            ->with($this->runtime, 1, 1, null);

        $this->instructionList
            ->expects($this->once())
            ->method('findInstruction')
            ->with(0xCD)
            ->willReturn($handler);

        $this->executeBytes([0xF1]);
    }

    public function testIntoInLongModeThrowsUd(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);

        $this->expectException(FaultException::class);
        $this->executeBytes([0xCE]);
    }

    public function testIntoWithOverflowFlagRaisesVector4(): void
    {
        $this->memoryAccessor->setOverflowFlag(true);

        $handler = $this->createMock(Int_::class);
        $handler
            ->expects($this->once())
            ->method('raiseSoftware')
            ->with($this->runtime, 4, 1, null);

        $this->instructionList
            ->expects($this->once())
            ->method('findInstruction')
            ->with(0xCD)
            ->willReturn($handler);

        $this->executeBytes([0xCE]);
    }

    public function testIntoWithoutOverflowFlagDoesNotRaise(): void
    {
        $this->memoryAccessor->setOverflowFlag(false);

        $this->instructionList
            ->expects($this->never())
            ->method('findInstruction');

        $this->executeBytes([0xCE]);
    }
}

