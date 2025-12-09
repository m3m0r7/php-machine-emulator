<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jnz implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x75];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
                ->memory()
            ->signedByte();

        $pos = $runtime
                ->memory()
            ->offset();

        $zf = $runtime->memoryAccessor()->shouldZeroFlag();
        $target = $pos + $operand;

        if ($runtime->option()->shouldChangeOffset() && !$zf) {
            $runtime
                ->memory()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
