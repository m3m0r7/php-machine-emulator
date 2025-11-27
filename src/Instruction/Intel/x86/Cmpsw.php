<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Cmpsw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xA7];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $opSize = $runtime->runtimeOption()->context()->operandSize();
        $width = $opSize === 32 ? 4 : 2;
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        $sourceSegment = $runtime->segmentOverride() ?? RegisterType::DS;

        $leftAddress = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $rightAddress = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        $left = $opSize === 32
            ? $this->readMemory32($runtime, $leftAddress)
            : $this->readMemory16($runtime, $leftAddress);
        $right = $opSize === 32
            ? $this->readMemory32($runtime, $rightAddress)
            : $this->readMemory16($runtime, $rightAddress);

        $runtime->memoryAccessor()->updateFlags($left - $right, $opSize)->setCarryFlag($left < $right);

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::ESI, $si + $step);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
