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
use PHPMachineEmulator\Util\UInt64;

/**
 * CMOVcc (0x0F 0x40-0x4F)
 * Conditional move.
 */
class Cmovcc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        $opcodes = [];
        for ($i = 0x40; $i <= 0x4F; $i++) {
            $opcodes[] = [0x0F, $i];
        }
        return $this->applyPrefixes($opcodes);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();
        $cpu = $runtime->context()->cpu();
        $opSize = $cpu->operandSize();

        $cc = ($opcode & 0xFF) & 0x0F;
        if ($opcode > 0xFF) {
            $cc = $opcode & 0x0F;
        }

        if (!$this->conditionMet($runtime, $cc)) {
            // Still consume addressing but don't perform move
            if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
                $this->rmLinearAddress($runtime, $memory, $modrm);
            }
            return ExecutionStatus::SUCCESS;
        }

        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        if ($isRegister) {
            $rmCode = $modrm->registerOrMemoryAddress();
            $rmReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
                ? Register::findGprByCode($rmCode, $cpu->rexB())
                : $rmCode;
            $value = $this->readRegisterBySize($runtime, $rmReg, $opSize);
        } else {
            $addr = $this->rmLinearAddress($runtime, $memory, $modrm);
            $value = match ($opSize) {
                16 => $this->readMemory16($runtime, $addr),
                32 => $this->readMemory32($runtime, $addr),
                64 => $this->readMemory64($runtime, $addr),
                default => $this->readMemory32($runtime, $addr),
            };
        }

        if ($value instanceof UInt64) {
            $value = $value->toInt();
        }

        $destRegCode = $modrm->registerOrOPCode();
        $destReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
            ? Register::findGprByCode($destRegCode, $cpu->rexR())
            : $destRegCode;
        $this->writeRegisterBySize($runtime, $destReg, $value, $opSize);

        return ExecutionStatus::SUCCESS;
    }

    private function conditionMet(RuntimeInterface $runtime, int $cc): bool
    {
        $ma = $runtime->memoryAccessor();
        $zf = $ma->shouldZeroFlag();
        $cf = $ma->shouldCarryFlag();
        $sf = $ma->shouldSignFlag();
        $of = $ma->shouldOverflowFlag();
        $pf = $ma->shouldParityFlag();

        return match ($cc) {
            0x0 => $of,
            0x1 => !$of,
            0x2 => $cf,
            0x3 => !$cf,
            0x4 => $zf,
            0x5 => !$zf,
            0x6 => $cf || $zf,
            0x7 => !$cf && !$zf,
            0x8 => $sf,
            0x9 => !$sf,
            0xA => $pf,
            0xB => !$pf,
            0xC => $sf !== $of,
            0xD => $sf === $of,
            0xE => $zf || ($sf !== $of),
            0xF => !$zf && ($sf === $of),
            default => false,
        };
    }
}
