<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86_64;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * REX prefix handler for x86-64 mode.
 *
 * REX prefixes (0x40-0x4F) are only valid in 64-bit mode.
 * In 32-bit and 16-bit modes, these opcodes are INC/DEC r32/r16.
 *
 * REX byte format:
 * - Bits 7-4: 0100 (fixed)
 * - Bit 3 (W): 64-bit operand size when set
 * - Bit 2 (R): Extension of ModR/M reg field
 * - Bit 1 (X): Extension of SIB index field
 * - Bit 0 (B): Extension of ModR/M r/m field, SIB base field, or opcode reg field
 *
 * The REX prefix must immediately precede the opcode byte (or VEX/EVEX prefix).
 * Multiple REX prefixes are not allowed; only the last one takes effect.
 */
class RexPrefix implements InstructionInterface
{
    public function __construct(protected InstructionListInterface $instructionList)
    {
    }

    public function opcodes(): array
    {
        // 0x40-0x4F are REX prefixes in 64-bit mode
        return range(0x40, 0x4F);
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        // REX prefix is only valid in 64-bit mode
        if (!$runtime->context()->cpu()->isLongMode() || $runtime->context()->cpu()->isCompatibilityMode()) {
            // In 32-bit/16-bit mode, 0x40-0x4F are INC/DEC instructions
            // This should not be called; the instruction dispatcher should route these differently
            throw new \RuntimeException('REX prefix used in non-64-bit mode');
        }

        // Extract REX bits from opcode
        $rex = $opcode & 0x0F;

        // Set REX state in CPU context
        $runtime->context()->cpu()->setRex($rex);

        // Read and execute the actual opcode that follows
        $nextOpcode = $runtime->memory()->byte();

        return $runtime->execute($nextOpcode);
    }
}
