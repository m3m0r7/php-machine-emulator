<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Frame\FrameSet;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Ret implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xC3];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $frameSet = $runtime
            ->frame()
            ->pop();

        if ($frameSet === null) {
            $runtime
                ->frame()
                ->append(
                    new FrameSet(
                        $runtime,
                        $this,
                        $runtime->streamReader()->offset(),
                    )
                );
            return ExecutionStatus::EXIT;
        }

        // NOTE: Back to previous frame stack
        $runtime
            ->streamReader()
            ->setOffset($frameSet->pos());

        return ExecutionStatus::SUCCESS;
    }
}
