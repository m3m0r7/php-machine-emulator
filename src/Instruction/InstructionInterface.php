<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface InstructionInterface
{
    public function opcodes(): array;
    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus;
}
