<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Lahf implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x9F];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $flags =
            ($runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0) |
            ($runtime->memoryAccessor()->shouldParityFlag() ? (1 << 2) : 0) |
            ($runtime->memoryAccessor()->shouldZeroFlag() ? (1 << 6) : 0) |
            ($runtime->memoryAccessor()->shouldSignFlag() ? (1 << 7) : 0);

        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToLowBit(RegisterType::EAX, $flags);

        return ExecutionStatus::SUCCESS;
    }
}
