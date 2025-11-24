<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Cmc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF5];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $runtime->memoryAccessor()->setCarryFlag(!$runtime->memoryAccessor()->shouldCarryFlag());
        return ExecutionStatus::SUCCESS;
    }
}
