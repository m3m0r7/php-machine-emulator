<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PopSeg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            0x07,
            0x17,
            0x1F,
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $seg = match ($opcode) {
            0x07 => RegisterType::ES,
            0x17 => RegisterType::SS,
            0x1F => RegisterType::DS,
        };

        $size = $runtime->runtimeOption()->context()->operandSize();

        $value = $runtime->memoryAccessor()->enableUpdateFlags(false)->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($seg, $value);

        return ExecutionStatus::SUCCESS;
    }
}
