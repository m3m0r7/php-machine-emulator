<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Clc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF8];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $runtime->memoryAccessor()->setCarryFlag(false);
        return ExecutionStatus::SUCCESS;
    }
}
