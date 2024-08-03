<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Nop implements InstructionInterface
{
    public function opcodes(): array
    {
        return [0x00];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        return ExecutionStatus::SUCCESS;
    }
}
