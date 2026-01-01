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
 * IMUL r16/32, r/m16/32 (0x0F 0xAF)
 * Two-operand signed multiply.
 */
class ImulRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0xAF]]);
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

        $regCode = $modrm->registerOrOPCode();
        $destReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
            ? Register::findGprByCode($regCode, $cpu->rexR())
            : $regCode;
        $dst = $this->readRegisterBySize($runtime, $destReg, $opSize);

        if ($opSize === 64) {
            $srcU = $src instanceof UInt64 ? $src : UInt64::of($src);
            $dstU = UInt64::of($dst);
            [$lowU, $highU] = $dstU->mulFullSigned($srcU);

            $this->writeRegisterBySize($runtime, $destReg, $lowU->toInt(), 64);

            $expectedHigh = $lowU->isNegativeSigned()
                ? UInt64::of('18446744073709551615')
                : UInt64::zero();
            $overflow = !$highU->eq($expectedHigh);
            $runtime->memoryAccessor()->setCarryFlag($overflow)->setOverflowFlag($overflow);
            return ExecutionStatus::SUCCESS;
        }

        $srcInt = $src instanceof UInt64 ? $src->toInt() : $src;
        $sSrc = $this->signExtend($srcInt, $opSize);
        $sDst = $this->signExtend($dst, $opSize);
        $product = $sSrc * $sDst;

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = $product & $mask;
        $this->writeRegisterBySize($runtime, $destReg, $result, $opSize);

        $min = -(1 << ($opSize - 1));
        $max = (1 << ($opSize - 1)) - 1;
        $overflow = $product < $min || $product > $max;
        $runtime->memoryAccessor()->setCarryFlag($overflow)->setOverflowFlag($overflow);

        return ExecutionStatus::SUCCESS;
    }
}
