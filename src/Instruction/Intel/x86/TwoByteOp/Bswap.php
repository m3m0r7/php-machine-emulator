<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * BSWAP (0x0F 0xC8-0xCF)
 * Byte swap register.
 */
class Bswap implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        $opcodes = [];
        for ($i = 0xC8; $i <= 0xCF; $i++) {
            $opcodes[] = [0x0F, $i];
        }
        return $this->applyPrefixes($opcodes);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $reg = ($opcode & 0xFF) & 0x7;
        if ($opcode > 0xFF) {
            $reg = $opcode & 0x7;
        }

        $opSize = $runtime->context()->cpu()->operandSize();
        if ($opSize !== 32) {
            return ExecutionStatus::SUCCESS;
        }

        $val = $runtime->memoryAccessor()->fetch($reg)->asBytesBySize(32);
        $swapped = (($val & 0xFF000000) >> 24)
            | (($val & 0x00FF0000) >> 8)
            | (($val & 0x0000FF00) << 8)
            | (($val & 0x000000FF) << 24);

        $this->writeRegisterBySize($runtime, $reg, $swapped & 0xFFFFFFFF, 32);

        return ExecutionStatus::SUCCESS;
    }
}
