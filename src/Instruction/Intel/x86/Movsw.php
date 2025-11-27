<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Movsw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xA5];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $opSize = $runtime->runtimeOption()->context()->operandSize();
        $width = $opSize === 32 ? 4 : 2;
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        $sourceSegment = $runtime->segmentOverride() ?? RegisterType::DS;

        $address = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $value = $opSize === 32
            ? $this->readMemory32($runtime, $address)
            : $this->readMemory16($runtime, $address);

        $destAddress = $this->translateLinear($runtime, $this->segmentOffsetAddress($runtime, RegisterType::ES, $di), true);
        $runtime->memoryAccessor()->allocate($destAddress, $width, safe: false);
        $runtime->memoryAccessor()->writeBySize($destAddress, $value, $opSize);

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::ESI, $si + $step);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
