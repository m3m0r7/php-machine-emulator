<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Nop implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        // 0x90 is NOP (XCHG EAX, EAX)
        // Note: 0x00 is ADD r/m8, r8, not NOP
        return [0x90];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        return ExecutionStatus::SUCCESS;
    }
}
