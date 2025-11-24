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
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asByte();

        $segment = $runtime->segmentOverride() ?? RegisterType::DS;

        $value = $this->readMemory16(
            $runtime,
            $this->segmentOffsetAddress($runtime, $segment, $si),
        );

        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::EAX, $value);

        $step = $runtime->memoryAccessor()->shouldDirectionFlag() ? -2 : 2;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->add(RegisterType::ESI, $step);

        return ExecutionStatus::SUCCESS;
    }
}
