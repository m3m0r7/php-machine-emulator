<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Pushf implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x9C]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $hasOperandSizeOverridePrefix = in_array(self::PREFIX_OPERAND_SIZE, $opcodes, true);
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $cpu = $runtime->context()->cpu();
        $size = $cpu->operandSize();
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $size = $hasOperandSizeOverridePrefix ? 16 : 64;
        }
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

        if ($cpu->isProtectedMode()) {
            $flags |= ($cpu->iopl() & 0x3) << 12;
            if ($cpu->nt()) {
                $flags |= (1 << 14);
            }
        }

        if ($size >= 32 && $cpu->idFlag()) {
            $flags |= (1 << 21);
        }

        $runtime
            ->memoryAccessor()
            ->push(RegisterType::ESP, $flags, $size);

        return ExecutionStatus::SUCCESS;
    }
}
