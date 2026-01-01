<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * DAA - Decimal Adjust AL after Addition
 *
 * Adjusts the sum of two packed BCD values to create a packed BCD result.
 * The AL register is the implied source and destination operand.
 */
class Daa implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x27]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();
        $al = $ma->fetch(RegisterType::EAX)->asLowBit();
        $oldAl = $al;
        $oldCf = $ma->shouldCarryFlag();
        $oldAf = $ma->shouldAuxiliaryCarryFlag();
        $af = false;
        $cf = false;

        // If the lower nibble of AL is greater than 9 or AF is set,
        // add 6 to AL and set AF
        if (($al & 0x0F) > 9 || $oldAf) {
            $al = ($al + 6) & 0xFF;
            $af = true;
        }

        // If the original AL was > 0x99 or CF was set,
        // add 0x60 to AL and set CF
        if ($oldAl > 0x99 || $oldCf) {
            $al = ($al + 0x60) & 0xFF;
            $cf = true;
        }

        // Write back the result
        $ma->writeToLowBit(RegisterType::EAX, $al);

        // Update flags (QEMU style)
        $ma->setAuxiliaryCarryFlag($af);
        $ma->setCarryFlag($cf);
        $ma->setZeroFlag($al === 0);
        $ma->setSignFlag(($al & 0x80) !== 0);
        $ma->setParityFlag(substr_count(decbin($al), '1') % 2 === 0);
        // OF is undefined after DAA

        return ExecutionStatus::SUCCESS;
    }
}
