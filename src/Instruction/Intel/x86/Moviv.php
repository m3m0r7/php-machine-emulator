<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Moviv implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xB4];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $operand = $runtime->streamReader()->byte();

        $runtime
            ->memoryAccessor()
            ->write(
                RegisterType::EAX,
                ($operand << 8) + ($runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte() & 0b11111111),
            );

        return ExecutionStatus::SUCCESS;
    }
}
