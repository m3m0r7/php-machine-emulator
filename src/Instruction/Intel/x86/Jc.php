<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x72];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        if ($runtime->memoryAccessor()->shouldCarryFlag()) {
            $runtime
                ->streamReader()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
