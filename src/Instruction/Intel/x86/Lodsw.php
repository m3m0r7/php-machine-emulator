<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class Lodsw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xAD]);
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

        $segment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        $address = $this->segmentOffsetAddress($runtime, $segment, $si);
        $value = match ($opSize) {
            16 => $this->readMemory16($runtime, $address),
            32 => $this->readMemory32($runtime, $address),
            64 => $this->readMemory64($runtime, $address),
            default => $this->readMemory16($runtime, $address),
        };

        $runtime->memoryAccessor()->writeBySize(
            RegisterType::EAX,
            $value instanceof UInt64 ? $value->toInt() : $value,
            $opSize
        );

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::ESI, $si + $step);

        return ExecutionStatus::SUCCESS;
    }
}
