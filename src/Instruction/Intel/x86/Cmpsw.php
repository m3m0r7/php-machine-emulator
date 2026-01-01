<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class Cmpsw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xA7]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opSize = $runtime->context()->cpu()->operandSize();
        $width = match ($opSize) {
            16 => 2,
            32 => 4,
            64 => 8,
            default => 2,
        };
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        $sourceSegment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        $leftAddress = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $rightAddress = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        $left = match ($opSize) {
            16 => $this->readMemory16($runtime, $leftAddress),
            32 => $this->readMemory32($runtime, $leftAddress),
            64 => $this->readMemory64($runtime, $leftAddress),
            default => $this->readMemory16($runtime, $leftAddress),
        };
        $right = match ($opSize) {
            16 => $this->readMemory16($runtime, $rightAddress),
            32 => $this->readMemory32($runtime, $rightAddress),
            64 => $this->readMemory64($runtime, $rightAddress),
            default => $this->readMemory16($runtime, $rightAddress),
        };

        if ($opSize === 64) {
            $leftU = $left instanceof UInt64 ? $left : UInt64::of($left);
            $rightU = $right instanceof UInt64 ? $right : UInt64::of($right);
            $leftInt = $leftU->toInt();
            $rightInt = $rightU->toInt();

            $resultU = $leftU->sub($rightU);
            $resultInt = $resultU->toInt();

            $cf = $leftU->lt($rightU);
            $af = (($leftInt & 0x0F) < ($rightInt & 0x0F));
            $of = (($leftInt < 0) !== ($rightInt < 0)) && (($resultInt < 0) === ($rightInt < 0));

            $runtime->memoryAccessor()
                ->updateFlags($resultInt, 64)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        } else {
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signBit = $opSize === 32 ? 31 : 15;
            $leftU = ($left instanceof UInt64 ? $left->toInt() : $left) & $mask;
            $rightU = ($right instanceof UInt64 ? $right->toInt() : $right) & $mask;

            $calc = $leftU - $rightU;
            $result = $calc & $mask;
            $cf = $calc < 0;
            $af = (($leftU & 0x0F) < ($rightU & 0x0F));

            $signA = ($leftU >> $signBit) & 1;
            $signB = ($rightU >> $signBit) & 1;
            $signR = ($result >> $signBit) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);

            $runtime->memoryAccessor()
                ->updateFlags($result, $opSize)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        }

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::ESI, $si + $step);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
