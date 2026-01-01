<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\ModType;
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
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();
        $cpu = $runtime->context()->cpu();
        $opSize = $cpu->operandSize();

        // Determine if byte or word source based on opcode
        $isByte = ($opcode & 0xFF) === 0xBE || ($opcode === 0x0FBE);

        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        if ($isRegister) {
            $rmCode = $modrm->registerOrMemoryAddress();

            if ($isByte) {
                $value = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()
                    ? $this->read8BitRegister64($runtime, $rmCode, true, $cpu->rexB())
                    : $this->read8BitRegister($runtime, $rmCode);
            } else {
                $rmReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
                    ? Register::findGprByCode($rmCode, $cpu->rexB())
                    : $rmCode;
                $value = $this->readRegisterBySize($runtime, $rmReg, 16);
            }
        } else {
            $addr = $this->rmLinearAddress($runtime, $memory, $modrm);
            $value = $isByte
                ? $this->readMemory8($runtime, $addr)
                : $this->readMemory16($runtime, $addr);
        }

        $value = $this->signExtend($value, $isByte ? 8 : 16);

        $destReg = $modrm->registerOrOPCode();
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $destReg = Register::findGprByCode($destReg, $cpu->rexR());
        }
        $this->writeRegisterBySize($runtime, $destReg, $value, $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
