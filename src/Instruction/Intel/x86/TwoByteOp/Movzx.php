<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * MOVZX (0x0F 0xB6 / 0x0F 0xB7)
 * Move with zero-extension.
 * 0xB6: MOVZX r16/32, r/m8
 * 0xB7: MOVZX r32, r/m16
 */
class Movzx implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([
            [0x0F, 0xB6], // MOVZX r16/32, r/m8
            [0x0F, 0xB7], // MOVZX r32, r/m16
        ], [PrefixClass::Operand, PrefixClass::Address, PrefixClass::Segment]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();

        // Determine if byte or word source based on opcode
        $isByte = ($opcode & 0xFF) === 0xB6 || ($opcode === 0x0FB6);

        $value = $isByte
            ? $this->readRm8($runtime, $memory, $modrm)
            : $this->readRm16($runtime, $memory, $modrm);

        $destReg = $modrm->registerOrOPCode();

        $this->writeRegisterBySize($runtime, $destReg, $value, $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
