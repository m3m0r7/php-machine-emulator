<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class JmpShort implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xEB];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        $runtime
            ->streamReader()
            ->setOffset($pos + $operand);

        return ExecutionStatus::SUCCESS;
    }
}
