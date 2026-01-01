<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * AAM - ASCII Adjust AX After Multiply
 *
 * Adjusts the result of the multiplication of two unpacked BCD values
 * to create a pair of unpacked (base 10) BCD values.
 *
 * Opcode: D4 ib (usually D4 0A for base 10)
 *
 * Operation:
 * tempAL ← AL;
 * AH ← tempAL / imm8; (integer division)
 * AL ← tempAL MOD imm8;
 *
 * The SF, ZF, and PF flags are set according to the resulting binary value in AL.
 * OF, AF, and CF are undefined.
 */
class Aam implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xD4]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();

        // Read the immediate byte (base, usually 0x0A for decimal)
        $base = $runtime->memory()->byte();

        // Check for division by zero
        if ($base === 0) {
            // #DE (Divide Error) - for simplicity, treat as NOP or throw
            $runtime->option()->logger()->warning('AAM: Division by zero (base=0)');
            return ExecutionStatus::SUCCESS;
        }

        $al = $ma->fetch(RegisterType::EAX)->asLowBit();

        // AH = AL / base (integer division)
        $ah = intdiv($al, $base);
        // AL = AL MOD base
        $newAl = $al % $base;

        $ma->writeToHighBit(RegisterType::EAX, $ah);
        $ma->writeToLowBit(RegisterType::EAX, $newAl);

        // Update SF, ZF, PF based on AL
        $ma->setSignFlag(($newAl & 0x80) !== 0);
        $ma->setZeroFlag($newAl === 0);
        $ma->setParityFlag(substr_count(decbin($newAl), '1') % 2 === 0);

        // OF, AF, CF are undefined

        return ExecutionStatus::SUCCESS;
    }
}
