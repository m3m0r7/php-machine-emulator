<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

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
        $cpu = $runtime->context()->cpu();
        $reg = ($opcode & 0xFF) & 0x7;
        if ($opcode > 0xFF) {
            $reg = $opcode & 0x7;
        }

        $size = ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexW()) ? 64 : 32;
        $rexExt = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB();
        $regType = Register::findGprByCode($reg, $rexExt);

        if ($size === 64) {
            $valU = UInt64::of($runtime->memoryAccessor()->fetch($regType)->asBytesBySize(64));
            $bytesBE = $valU->toBytes(8, littleEndian: false);
            $swappedU = UInt64::fromBytes(strrev($bytesBE), littleEndian: false);
            $runtime->memoryAccessor()->writeBySize($regType, $swappedU->toInt(), 64);
            return ExecutionStatus::SUCCESS;
        }

        $val = $runtime->memoryAccessor()->fetch($regType)->asBytesBySize(32);
        $swapped = (($val & 0xFF000000) >> 24)
            | (($val & 0x00FF0000) >> 8)
            | (($val & 0x0000FF00) << 8)
            | (($val & 0x000000FF) << 24);
        $this->writeRegisterBySize($runtime, $regType, $swapped & 0xFFFFFFFF, 32);

        return ExecutionStatus::SUCCESS;
    }
}
