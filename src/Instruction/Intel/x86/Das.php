<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * DAS - Decimal Adjust AL after Subtraction
 *
 * Adjusts the result of the subtraction of two packed BCD values to create
 * a packed BCD result. The AL register is the implied source and destination operand.
 */
class Das implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x2F];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $al = $ma->fetch(RegisterType::EAX)->asLowBit();
        $oldAl = $al;
        $oldCf = $ma->shouldCarryFlag();
        $cf = false;

        // If the lower nibble of AL is greater than 9 or AF is set,
        // subtract 6 from AL and set AF
        if (($al & 0x0F) > 9 || $ma->shouldAuxiliaryCarryFlag()) {
            $al = ($al - 6) & 0xFF;
            $ma->setAuxiliaryCarryFlag(true);
            $cf = $oldCf || ($oldAl < 6); // Check for borrow
        } else {
            $ma->setAuxiliaryCarryFlag(false);
        }

        // If the original AL was greater than 0x99 or CF was set,
        // subtract 0x60 from AL and set CF
        if ($oldAl > 0x99 || $oldCf) {
            $al = ($al - 0x60) & 0xFF;
            $cf = true;
        }

        // Write back the result
        $ma->enableUpdateFlags(false)->writeToLowBit(RegisterType::EAX, $al);

        // Update flags
        $ma->setCarryFlag($cf);
        $ma->setZeroFlag($al === 0);
        $ma->setSignFlag(($al & 0x80) !== 0);
        $ma->setParityFlag(substr_count(decbin($al), '1') % 2 === 0);
        // OF is undefined after DAS

        return ExecutionStatus::SUCCESS;
    }
}
