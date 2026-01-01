<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Exception\InvalidOpcodeException;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\MovRmToSeg;
use PHPMachineEmulator\Instruction\Intel\x86\MovSegToRm;

final class MovSegEncodingTest extends InstructionTestCase
{
    private MovSegToRm $movSegToRm;
    private MovRmToSeg $movRmToSeg;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->movSegToRm = new MovSegToRm($instructionList);
        $this->movRmToSeg = new MovRmToSeg($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0x8C => $this->movSegToRm,
            0x8E => $this->movRmToSeg,
            default => null,
        };
    }

    public function testMovSegToRmInvalidSegmentEncodingRaisesUd(): void
    {
        $this->expectException(InvalidOpcodeException::class);
        $this->executeBytes([0x8C, 0xFF]);
    }

    public function testMovRmToSegInvalidSegmentEncodingRaisesUd(): void
    {
        $this->expectException(InvalidOpcodeException::class);
        $this->executeBytes([0x8E, 0xFF]);
    }
}
