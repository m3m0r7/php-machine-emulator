<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * LOCK prefix (0xF0). Ignored in this emulator; delegates to the following opcode.
 */
class LockPrefix implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF0];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        // LOCK prefix is ignored in this emulator (no multi-processor support)
        return ExecutionStatus::CONTINUE;
    }
}
