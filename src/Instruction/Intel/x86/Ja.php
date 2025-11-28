<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * JA rel8 - Jump short if above (CF=0 AND ZF=0)
 * Also known as JNBE (Jump short if not below or equal)
 */
class Ja implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x77];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        $cf = $runtime->memoryAccessor()->shouldCarryFlag();
        $zf = $runtime->memoryAccessor()->shouldZeroFlag();
        // JA: Jump if CF=0 AND ZF=0 (unsigned greater than)
        $taken = !$cf && !$zf;
        $target = $pos + $operand;

        if ($runtime->option()->shouldChangeOffset() && $taken) {
            $runtime
                ->streamReader()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
