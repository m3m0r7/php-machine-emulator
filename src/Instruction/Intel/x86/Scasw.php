<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class Scasw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xAF]);
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
        $di = $this->readIndex($runtime, RegisterType::EDI);

        $address = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);
        $value = match ($opSize) {
            16 => $this->readMemory16($runtime, $address),
            32 => $this->readMemory32($runtime, $address),
            64 => $this->readMemory64($runtime, $address),
            default => $this->readMemory16($runtime, $address),
        };

        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);

        if ($opSize === 64) {
            $valueInt = $value instanceof UInt64 ? $value->toInt() : $value;
            $axU = UInt64::of($ax);
            $valueU = $value instanceof UInt64 ? $value : UInt64::of($value);
            $resultU = $axU->sub($valueU);
            $resultInt = $resultU->toInt();

            $cf = $axU->lt($valueU);
            $af = (($ax & 0x0F) < ($valueInt & 0x0F));
            $of = (($ax < 0) !== ($valueInt < 0)) && (($resultInt < 0) === ($valueInt < 0));

            $runtime->memoryAccessor()
                ->updateFlags($resultInt, 64)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        } else {
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signBit = $opSize === 32 ? 31 : 15;
            $axU = $ax & $mask;
            $valueU = ($value instanceof UInt64 ? $value->toInt() : $value) & $mask;

            $calc = $axU - $valueU;
            $result = $calc & $mask;
            $cf = $calc < 0;
            $af = (($axU & 0x0F) < ($valueU & 0x0F));

            $signA = ($axU >> $signBit) & 1;
            $signB = ($valueU >> $signBit) & 1;
            $signR = ($result >> $signBit) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);

            $runtime->memoryAccessor()
                ->updateFlags($result, $opSize)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        }

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
