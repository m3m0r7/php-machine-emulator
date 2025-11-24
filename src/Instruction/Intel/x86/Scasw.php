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
        $di = $runtime->memoryAccessor()->fetch(RegisterType::EDI)->asByte();

        $value = $this->readMemory16(
            $runtime,
            $this->segmentOffsetAddress($runtime, RegisterType::ES, $di),
        );
        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();

        $runtime->memoryAccessor()->updateFlags($ax - $value, 16)->setCarryFlag($ax < $value);

        $step = $runtime->memoryAccessor()->shouldDirectionFlag() ? -2 : 2;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->add(RegisterType::EDI, $step);

        return ExecutionStatus::SUCCESS;
    }
}
