<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Cld implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xFC];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $runtime->memoryAccessor()->setDirectionFlag(false);
        return ExecutionStatus::SUCCESS;
    }
}
