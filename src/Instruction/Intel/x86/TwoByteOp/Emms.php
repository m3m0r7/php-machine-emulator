<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * EMMS (0x0F 0x77)
 * Empty MMX state - treated as no-op.
 */
class Emms implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [[0x0F, 0x77]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        // No-op - MMX state not fully modeled
        return ExecutionStatus::SUCCESS;
    }
}
