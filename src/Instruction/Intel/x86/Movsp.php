<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Movsp implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return array_keys($this->stackPointers());
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $low = $runtime->streamReader()->byte();
        $high = $runtime->streamReader()->byte();

        $runtime
            ->memoryAccessor()
            ->write(
                $this->stackPointers()[$opcode],
                ($high << 8) + $low,
            );

        return ExecutionStatus::SUCCESS;
    }

    private function stackPointers(): array
    {
        return [
            0xB8 + ($this->instructionList->register())::addressBy(RegisterType::ESP) => RegisterType::ESP,
            0xB8 + ($this->instructionList->register())::addressBy(RegisterType::EBP) => RegisterType::EBP,
        ];
    }
}
