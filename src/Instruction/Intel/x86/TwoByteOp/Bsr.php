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
 * BSR (0x0F 0xBD)
 * Bit scan reverse.
 */
class Bsr implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0xBD]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();
        $cpu = $runtime->context()->cpu();
        $opSize = $cpu->operandSize();

        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        if ($isRegister) {
            $rmCode = $modrm->registerOrMemoryAddress();
            $rmReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
                ? Register::findGprByCode($rmCode, $cpu->rexB())
                : $rmCode;
            $src = $this->readRegisterBySize($runtime, $rmReg, $opSize);
        } else {
            $addr = $this->rmLinearAddress($runtime, $memory, $modrm);
            $src = match ($opSize) {
                16 => $this->readMemory16($runtime, $addr),
                32 => $this->readMemory32($runtime, $addr),
                64 => $this->readMemory64($runtime, $addr),
                default => $this->readMemory32($runtime, $addr),
            };
        }

        if ($opSize === 64) {
            $srcU = $src instanceof UInt64 ? $src : UInt64::of($src);
            if ($srcU->isZero()) {
                $runtime->memoryAccessor()->setZeroFlag(true);
                return ExecutionStatus::SUCCESS;
            }
            $runtime->memoryAccessor()->setZeroFlag(false);

            $high = $srcU->high32();
            $low = $srcU->low32();
            $index = $high !== 0 ? ($this->bsr32($high) + 32) : $this->bsr32($low);

            $destRegCode = $modrm->registerOrOPCode();
            $destReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
                ? Register::findGprByCode($destRegCode, $cpu->rexR())
                : $destRegCode;
            $this->writeRegisterBySize($runtime, $destReg, $index, 64);
            return ExecutionStatus::SUCCESS;
        }

        $srcInt = $src instanceof UInt64 ? $src->toInt() : $src;
        if ($srcInt === 0) {
            $runtime->memoryAccessor()->setZeroFlag(true);
            return ExecutionStatus::SUCCESS;
        }

        $runtime->memoryAccessor()->setZeroFlag(false);

        $index = $opSize - 1;
        while ($index >= 0 && ((($srcInt >> $index) & 1) === 0)) {
            $index--;
        }

        $destRegCode = $modrm->registerOrOPCode();
        $destReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
            ? Register::findGprByCode($destRegCode, $cpu->rexR())
            : $destRegCode;
        $this->writeRegisterBySize($runtime, $destReg, $index, $opSize);

        return ExecutionStatus::SUCCESS;
    }

    private function bsr32(int $value): int
    {
        $index = 31;
        while ($index >= 0 && ((($value >> $index) & 1) === 0)) {
            $index--;
        }
        return $index;
    }
}
