<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Exception\NotFoundInstructionException;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;

class TestInstructionList implements InstructionListInterface
{
    public function findInstruction(array $opcodes): InstructionInterface
    {
        throw new NotFoundInstructionException($opcodes);
    }

    public function getMaxOpcodeLength(): int
    {
        return 4;
    }
}
