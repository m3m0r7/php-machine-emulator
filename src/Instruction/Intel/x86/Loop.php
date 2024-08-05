<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Loop implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE2];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        $counter = $runtime->memoryAccessor()
            ->fetch(RegisterType::ECX)->asByte() - 1;

        if ($counter < 0) {
            return ExecutionStatus::SUCCESS;
        }

        $runtime->memoryAccessor()
            ->decrement(RegisterType::ECX);

        $runtime
            ->streamReader()
            ->setOffset($pos + $operand);

        return ExecutionStatus::SUCCESS;
    }
}
