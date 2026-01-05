<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Simplified I/O to trigger HALT when keyboard controller requests.
 */
class HltPorts implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF4];
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        return ExecutionStatus::SUCCESS;
    }
}
