<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jz implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x74];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        if ($runtime->memoryAccessor()->shouldZeroFlag()) {
            $runtime
                ->streamReader()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
