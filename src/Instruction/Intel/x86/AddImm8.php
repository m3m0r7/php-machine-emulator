<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class AddImm8 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x04];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime->streamReader()->byte();

        $value = $runtime
                ->memoryAccessor()
                ->fetch(RegisterType::EAX)
                ->asLowBit() + $operand;

        $runtime
            ->memoryAccessor()
            ->writeToLowBit(
                RegisterType::EAX,
                $value,
            )
            ->setCarryFlag($value > 0xFF);

        return ExecutionStatus::SUCCESS;
    }
}
