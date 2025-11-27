<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Scasw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xAF];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $width = $opSize === 32 ? 4 : 2;
        $di = $this->readIndex($runtime, RegisterType::EDI);

        $address = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);
        $value = $opSize === 32
            ? $this->readMemory32($runtime, $address)
            : $this->readMemory16($runtime, $address);

        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);

        $runtime->memoryAccessor()->updateFlags($ax - $value, $opSize)->setCarryFlag($ax < $value);

        $step = $this->stepForElement($runtime, $width);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
