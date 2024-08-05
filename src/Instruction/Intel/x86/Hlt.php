<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Hlt implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF4];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        return ExecutionStatus::HALT;
    }
}
