<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jnc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x73];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        // JNC: Jump if Not Carry (CF=0)
        if ($runtime->option()->shouldChangeOffset() && !$runtime->memoryAccessor()->shouldCarryFlag()) {
            $runtime
                ->streamReader()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
