<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PushSeg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            0x06,
            0x0E,
            0x16,
            0x1E,
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $seg = match ($opcode) {
            0x06 => RegisterType::ES,
            0x0E => RegisterType::CS,
            0x16 => RegisterType::SS,
            0x1E => RegisterType::DS,
        };

        $value = $runtime->memoryAccessor()->fetch($seg)->asByte();
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(
            RegisterType::ESP,
            $value,
            $runtime->context()->cpu()->operandSize(),
        );

        return ExecutionStatus::SUCCESS;
    }
}
