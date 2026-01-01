<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class Movsw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xA5]);
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

        $address = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $value = match ($opSize) {
            16 => $this->readMemory16($runtime, $address),
            32 => $this->readMemory32($runtime, $address),
            64 => $this->readMemory64($runtime, $address),
            default => $this->readMemory16($runtime, $address),
        };

        $destLinear = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);
        // Linear framebuffer writes (e.g. VBE LFB at 0xE0000000) must go through MMIO handling.
        if ($destLinear >= 0xE0000000 && $destLinear < 0xE1000000) {
            match ($opSize) {
                16 => $this->writeMemory16($runtime, $destLinear, is_int($value) ? $value : $value->toInt()),
                32 => $this->writeMemory32($runtime, $destLinear, is_int($value) ? $value : $value->toInt()),
                64 => $this->writeMemory64($runtime, $destLinear, $value),
                default => $this->writeMemory16($runtime, $destLinear, is_int($value) ? $value : $value->toInt()),
            };
        } else {
            match ($opSize) {
                16 => $this->writeMemory16($runtime, $destLinear, is_int($value) ? $value : $value->toInt()),
                32 => $this->writeMemory32($runtime, $destLinear, is_int($value) ? $value : $value->toInt()),
                64 => $this->writeMemory64($runtime, $destLinear, $value),
                default => $this->writeMemory16($runtime, $destLinear, is_int($value) ? $value : $value->toInt()),
            };
        }

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::ESI, $si + $step);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
