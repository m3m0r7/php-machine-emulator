<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Lodsw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xAD];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $width = $opSize === 32 ? 4 : 2;
        $si = $this->readIndex($runtime, RegisterType::ESI);

        $segment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        $address = $this->segmentOffsetAddress($runtime, $segment, $si);
        $value = $opSize === 32
            ? $this->readMemory32($runtime, $address)
            : $this->readMemory16($runtime, $address);

        $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $value, $opSize);

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::ESI, $si + $step);

        return ExecutionStatus::SUCCESS;
    }
}
