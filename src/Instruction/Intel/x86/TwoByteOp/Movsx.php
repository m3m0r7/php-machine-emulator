<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * MOVSX (0x0F 0xBE / 0x0F 0xBF)
 * Move with sign-extension.
 * 0xBE: MOVSX r16/32, r/m8
 * 0xBF: MOVSX r32, r/m16
 */
class Movsx implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([
            [0x0F, 0xBE], // MOVSX r16/32, r/m8
            [0x0F, 0xBF], // MOVSX r32, r/m16
        ], [PrefixClass::Operand, PrefixClass::Address, PrefixClass::Segment]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();

        // Determine if byte or word source based on opcode
        $isByte = ($opcode & 0xFF) === 0xBE || ($opcode === 0x0FBE);

        $value = $isByte
            ? $this->readRm8($runtime, $reader, $modrm)
            : $this->readRm16($runtime, $reader, $modrm);

        // Sign extend the value
        if ($isByte) {
            // Sign extend 8-bit to 16/32-bit
            if ($value & 0x80) {
                $value = $opSize === 32 ? ($value | 0xFFFFFF00) : ($value | 0xFF00);
            }
        } else {
            // Sign extend 16-bit to 32-bit
            if ($opSize === 32 && ($value & 0x8000)) {
                $value = $value | 0xFFFF0000;
            }
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $value & $mask, $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
