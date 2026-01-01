<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * AAD - ASCII Adjust AX Before Division
 *
 * Adjusts two unpacked BCD digits (the least-significant digit in the AL register
 * and the most-significant digit in the AH register) so that a division operation
 * performed on the result will yield a correct unpacked BCD value.
 *
 * Opcode: D5 ib (usually D5 0A for base 10)
 *
 * Operation:
 * tempAL ← AL;
 * tempAH ← AH;
 * AL ← (tempAL + (tempAH * imm8)) AND 0xFF;
 * AH ← 0;
 *
 * The SF, ZF, and PF flags are set according to the resulting binary value in AL.
 * OF, AF, and CF are undefined.
 */
class Aad implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xD5]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();

        // Read the immediate byte (base, usually 0x0A for decimal)
        $base = $runtime->memory()->byte();

        $al = $ma->fetch(RegisterType::EAX)->asLowBit();
        $ah = $ma->fetch(RegisterType::EAX)->asHighBit();

        // AL = (AL + (AH * base)) AND 0xFF
        $result = ($al + ($ah * $base)) & 0xFF;

        // AH = 0
        $ma->writeToHighBit(RegisterType::EAX, 0);
        $ma->writeToLowBit(RegisterType::EAX, $result);

        // Update SF, ZF, PF
        $ma->setSignFlag(($result & 0x80) !== 0);
        $ma->setZeroFlag($result === 0);
        $ma->setParityFlag(substr_count(decbin($result), '1') % 2 === 0);

        // OF, AF, CF are undefined

        return ExecutionStatus::SUCCESS;
    }
}
