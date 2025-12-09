<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * AAA - ASCII Adjust After Addition
 *
 * Adjusts the sum of two unpacked BCD values to create an unpacked BCD result.
 * The AL register is the implied source and destination operand for the addition.
 * The AH register is the implied destination operand.
 *
 * Operation:
 * IF ((AL AND 0FH) > 9) OR (AF = 1) THEN
 *     AL ← (AL + 6) AND 0FH;
 *     AH ← AH + 1;
 *     AF ← 1;
 *     CF ← 1;
 * ELSE
 *     AF ← 0;
 *     CF ← 0;
 * FI;
 * AL ← AL AND 0FH;
 */
class Aaa implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x37]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();
        $al = $ma->fetch(RegisterType::EAX)->asLowBit();
        $ah = $ma->fetch(RegisterType::EAX)->asHighBit();

        // If the lower nibble of AL is greater than 9 or AF is set
        if (($al & 0x0F) > 9 || $ma->shouldAuxiliaryCarryFlag()) {
            // AL = (AL + 6) AND 0Fh
            $al = (($al + 6) & 0x0F);
            // AH = AH + 1
            $ah = ($ah + 1) & 0xFF;
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
        $ma->writeToLowBit(RegisterType::EAX, $al);
        $ma->writeToHighBit(RegisterType::EAX, $ah);

        // Note: OF, SF, ZF, PF are undefined after AAA

        return ExecutionStatus::SUCCESS;
    }
}
