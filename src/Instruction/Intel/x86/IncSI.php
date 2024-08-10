<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class IncSI implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x46];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $runtime
            ->memoryAccessor()
            ->increment(RegisterType::ESI);

        return ExecutionStatus::SUCCESS;
    }
}
