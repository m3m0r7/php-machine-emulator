<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Operand-size override (0x66). Currently delegates to the next opcode without changing size semantics.
 */
class OperandSizePrefix implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x66];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        // Flag the runtime context for this instruction to prefer 32-bit operands.
        $runtime->context()->cpu()->setOperandSizeOverride(true);
        $nextOpcode = $runtime->memory()->byte();
        return $runtime->execute($nextOpcode);
    }
}
