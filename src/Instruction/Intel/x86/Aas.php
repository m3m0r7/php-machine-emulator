<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * AAS - ASCII Adjust After Subtraction
 *
 * Adjusts the result of the subtraction of two unpacked BCD values
 * to create an unpacked BCD result.
 * The AL register is the implied source and destination operand.
 * The AH register is the implied source and destination operand.
 *
 * Operation:
 * IF ((AL AND 0FH) > 9) OR (AF = 1) THEN
 *     AL ← AL - 6;
 *     AH ← AH - 1;
 *     AF ← 1;
 *     CF ← 1;
 *     AL ← AL AND 0FH;
 * ELSE
 *     CF ← 0;
 *     AF ← 0;
 *     AL ← AL AND 0FH;
 * FI;
 */
class Aas implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x3F];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $al = $ma->fetch(RegisterType::EAX)->asLowBit();
        $ah = $ma->fetch(RegisterType::EAX)->asHighBit();

        // If the lower nibble of AL is greater than 9 or AF is set
        if (($al & 0x0F) > 9 || $ma->shouldAuxiliaryCarryFlag()) {
            // AL = AL - 6
            $al = ($al - 6) & 0xFF;
            // AH = AH - 1
            $ah = ($ah - 1) & 0xFF;
            // Set AF and CF
            $ma->setAuxiliaryCarryFlag(true);
            $ma->setCarryFlag(true);
        } else {
            // Clear AF and CF
            $ma->setAuxiliaryCarryFlag(false);
            $ma->setCarryFlag(false);
        }

        // AL = AL AND 0Fh (keep only lower nibble)
        $al = $al & 0x0F;

        // Write back the results
        $ma->enableUpdateFlags(false)->writeToLowBit(RegisterType::EAX, $al);
        $ma->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, $ah);

        // Note: OF, SF, ZF, PF are undefined after AAS

        return ExecutionStatus::SUCCESS;
    }
}
