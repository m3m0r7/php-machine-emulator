<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Movsg implements InstructionInterface
{
    public function opcodes(): array
    {
        return [0x8E];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        return ExecutionStatus::SUCCESS;
    }
}
