<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86_64;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

/**
 * MOVSXD - Move with Sign-Extension Doubleword (64-bit mode only).
 *
 * Opcode: 0x63
 * In 64-bit mode: MOVSXD r64, r/m32 (sign-extend 32-bit to 64-bit)
 * In 32-bit mode: This opcode is ARPL (Adjust RPL Field of Segment Selector)
 *
 * With REX.W:
 *   MOVSXD r64, r/m32 - Read 32-bit value, sign-extend to 64-bit
 *
 * Without REX.W (but in 64-bit mode):
 *   MOVSXD r32, r/m32 - Same as MOV r32, r/m32 (no sign extension needed)
 */
class Movsxd implements InstructionInterface
{
    use Instructable64;

    public function opcodes(): array
    {
        return [0x63];
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $reader = $this->enhanceReader($runtime);
        $modRegRM = $reader->byteAsModRegRM();

        $cpu = $runtime->context()->cpu();

        if ($cpu->rexW()) {
            // MOVSXD r64, r/m32: Read 32-bit, sign-extend to 64-bit
            $value32 = $this->readRm($runtime, $reader, $modRegRM, 32);

            // Sign-extend 32-bit to 64-bit using UInt64
            $value64 = UInt64::of($value32 & 0xFFFFFFFF);
            if (($value32 & 0x80000000) !== 0) {
                // Set upper 32 bits for sign extension
                $value64 = $value64->or('18446744069414584320'); // 0xFFFFFFFF00000000
            }

            // Write to 64-bit register (with REX.R extension)
            $regCode = $modRegRM->register();
            if ($cpu->rexR()) {
                $regCode |= 0b1000;  // R8-R15
            }
            $this->writeRegisterBySize($runtime, $regCode, $value64->toInt(), 64);
        } else {
            // Without REX.W: acts as 32-bit MOV
            $value32 = $this->readRm($runtime, $reader, $modRegRM, 32);
            $regCode = $modRegRM->register();
            $this->writeRegisterBySize($runtime, $regCode, $value32, 32);
        }

        return ExecutionStatus::SUCCESS;
    }
}
