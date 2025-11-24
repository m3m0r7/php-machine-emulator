<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Cli extends Nop
{
    public function opcodes(): array
    {
        return [0xFA];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $runtime->memoryAccessor()->setInterruptFlag(false);
        return ExecutionStatus::SUCCESS;
    }
}
