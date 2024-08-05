<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Frame\FrameSet;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Call implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE8];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $byte1 = $runtime->streamReader()->byte();
        $byte2 = $runtime->streamReader()->byte();

        $pos = $runtime->streamReader()->offset();

        $runtime
            ->streamReader()
            ->setOffset($pos + ($byte2 << 8) + $byte1);

        $runtime->frame()
            ->append(new FrameSet($runtime, $this, $pos));

        return ExecutionStatus::SUCCESS;
    }
}
