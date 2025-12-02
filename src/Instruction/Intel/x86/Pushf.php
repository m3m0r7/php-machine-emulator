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
        $size = $runtime->context()->cpu()->operandSize();
        $ma = $runtime->memoryAccessor();
        $flags =
            ($ma->shouldCarryFlag() ? 1 : 0) |
            0x2 | // reserved bit always set
            ($ma->shouldParityFlag() ? (1 << 2) : 0) |
            ($ma->shouldAuxiliaryCarryFlag() ? (1 << 4) : 0) |
            ($ma->shouldZeroFlag() ? (1 << 6) : 0) |
            ($ma->shouldSignFlag() ? (1 << 7) : 0) |
            ($ma->shouldInterruptFlag() ? (1 << 9) : 0) |
            ($ma->shouldDirectionFlag() ? (1 << 10) : 0) |
            ($ma->shouldOverflowFlag() ? (1 << 11) : 0);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $flags |= ($runtime->context()->cpu()->iopl() & 0x3) << 12;
            if ($runtime->context()->cpu()->nt()) {
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
