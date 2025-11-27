<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Cmpsb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xA6];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        $sourceSegment = $runtime->segmentOverride() ?? RegisterType::DS;

        $left = $this->readMemory8(
            $runtime,
            $this->segmentOffsetAddress($runtime, $sourceSegment, $si),
        );
        $right = $this->readMemory8(
            $runtime,
            $this->segmentOffsetAddress($runtime, RegisterType::ES, $di),
        );

        $runtime->memoryAccessor()->updateFlags($left - $right, 8)->setCarryFlag($left < $right);

        $step = $this->stepForElement($runtime, 1);
        $this->writeIndex($runtime, RegisterType::ESI, $si + $step);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
