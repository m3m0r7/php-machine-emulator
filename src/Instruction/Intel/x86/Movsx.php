<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Movsx implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return array_keys($this->indexPointers());
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());

        $targetOPCode = $this->indexPointers()[$opcode];
        $runtime
            ->memoryAccessor()
            ->write(
                $targetOPCode,
                $enhancedStreamReader->short(),
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
