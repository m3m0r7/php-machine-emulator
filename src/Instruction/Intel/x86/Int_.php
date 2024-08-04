<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Int_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xCD];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->byte();

        // The BIOS video interrupt
        if ($operand === 0x10) {
            echo chr($runtime->memoryAccessor()->fetch(RegisterType::EAX));
            return ExecutionStatus::SUCCESS;
        }

        throw new RuntimeException('Not implemented interrupt types');
    }
}
