<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

interface InstructionListInterface
{
    public function register(): RegisterInterface;
    public function getInstructionByOperationCode(int $opcode): InstructionInterface;
}
