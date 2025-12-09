<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

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
        $width = $opSize === 32 ? 4 : 2;
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        $sourceSegment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        $leftAddress = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $rightAddress = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        $left = $opSize === 32
            ? $this->readMemory32($runtime, $leftAddress)
            : $this->readMemory16($runtime, $leftAddress);
        $right = $opSize === 32
            ? $this->readMemory32($runtime, $rightAddress)
            : $this->readMemory16($runtime, $rightAddress);

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $signBit = $opSize === 32 ? 31 : 15;
        $calc = $left - $right;
        $result = $calc & $mask;
        // OF for CMP/CMPS: set if signs of operands differ and result sign equals subtrahend sign
        $signA = ($left >> $signBit) & 1;
        $signB = ($right >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        $runtime->memoryAccessor()
            ->updateFlags($result, $opSize)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of);

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::ESI, $si + $step);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
