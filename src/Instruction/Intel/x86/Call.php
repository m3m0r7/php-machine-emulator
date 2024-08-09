<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Frame\FrameSet;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
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
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());

        $offset = $enhancedStreamReader
            ->signedShort();

        $pos = $runtime
            ->streamReader()
            ->offset();

        $runtime
            ->streamReader()
            ->setOffset($pos + $offset);

        $runtime->frame()
            ->append(new FrameSet($runtime, $this, $pos));

        return ExecutionStatus::SUCCESS;
    }
}
