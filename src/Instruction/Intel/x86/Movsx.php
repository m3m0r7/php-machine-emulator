<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Movsx implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return array_keys($this->indexPointers());
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $operand1 = $runtime->streamReader()->byte();
        $operand2 = $runtime->streamReader()->byte();

        $runtime->memoryAccessor()
            ->write(
                $this->indexPointers()[$opcode],
                ($operand2 << 8) + $operand1,
            );

        return ExecutionStatus::SUCCESS;
    }

    private function indexPointers(): array
    {
        return [
            0xB8 + ($this->instructionList->register())::addressBy(RegisterType::ESI) => RegisterType::ESI,
            0xB8 + ($this->instructionList->register())::addressBy(RegisterType::EDI) => RegisterType::EDI,
        ];
    }
}
