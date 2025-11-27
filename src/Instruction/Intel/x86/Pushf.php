<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Pushf implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x9C];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $size = $runtime->runtimeOption()->context()->operandSize();
        $flags =
            ($runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0) |
            0x2 |
            ($runtime->memoryAccessor()->shouldParityFlag() ? (1 << 2) : 0) |
            ($runtime->memoryAccessor()->shouldZeroFlag() ? (1 << 6) : 0) |
            ($runtime->memoryAccessor()->shouldSignFlag() ? (1 << 7) : 0) |
            ($runtime->memoryAccessor()->shouldInterruptFlag() ? (1 << 9) : 0) |
            ($runtime->memoryAccessor()->shouldDirectionFlag() ? (1 << 10) : 0) |
            ($runtime->memoryAccessor()->shouldOverflowFlag() ? (1 << 11) : 0);

        if ($runtime->runtimeOption()->context()->isProtectedMode()) {
            $flags |= ($runtime->runtimeOption()->context()->iopl() & 0x3) << 12;
            if ($runtime->runtimeOption()->context()->nt()) {
                $flags |= (1 << 14);
            }
        }

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->push(RegisterType::ESP, $flags, $size);

        return ExecutionStatus::SUCCESS;
    }
}
