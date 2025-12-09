<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Jcc near (0x0F 0x80-0x8F)
 * Conditional jump with near displacement.
 */
class JccNear implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        $opcodes = [];
        for ($i = 0x80; $i <= 0x8F; $i++) {
            $opcodes[] = [0x0F, $i];
        }
        return $this->applyPrefixes($opcodes);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $cc = ($opcode & 0xFF) & 0x0F;
        if ($opcode > 0xFF) {
            // Combined opcode key (e.g., 0x0F80)
            $cc = $opcode & 0x0F;
        }

        $opSize = $runtime->context()->cpu()->operandSize();
        $disp = $opSize === 32
            ? $runtime->memory()->dword()
            : $runtime->memory()->short();

        // Sign-extend displacement
        if ($opSize === 32) {
            $disp = (int) (pack('V', $disp) === false ? $disp : unpack('l', pack('V', $disp))[1]);
        } else {
            if ($disp > 0x7FFF) {
                $disp = $disp - 0x10000;
            }
        }

        if ($this->conditionMet($runtime, $cc)) {
            $current = $runtime->memory()->offset();
            $runtime->memory()->setOffset(($current + $disp) & 0xFFFFFFFF);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function conditionMet(RuntimeInterface $runtime, int $cc): bool
    {
        $ma = $runtime->memoryAccessor();
        $zf = $ma->shouldZeroFlag();
        $cf = $ma->shouldCarryFlag();
        $sf = $ma->shouldSignFlag();
        $of = $ma->shouldOverflowFlag();
        $pf = $ma->shouldParityFlag();

        return match ($cc) {
            0x0 => $of,
            0x1 => !$of,
            0x2 => $cf,
            0x3 => !$cf,
            0x4 => $zf,
            0x5 => !$zf,
            0x6 => $cf || $zf,
            0x7 => !$cf && !$zf,
            0x8 => $sf,
            0x9 => !$sf,
            0xA => $pf,
            0xB => !$pf,
            0xC => $sf !== $of,
            0xD => $sf === $of,
            0xE => $zf || ($sf !== $of),
            0xF => !$zf && ($sf === $of),
            default => false,
        };
    }
}
