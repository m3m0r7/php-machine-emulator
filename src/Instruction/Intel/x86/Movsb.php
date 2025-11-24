<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Movsb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xA4];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asByte();
        $di = $runtime->memoryAccessor()->fetch(RegisterType::EDI)->asByte();

        $sourceSegment = $runtime->segmentOverride() ?? RegisterType::DS;

        $value = $this->readMemory8(
            $runtime,
            $this->segmentOffsetAddress($runtime, $sourceSegment, $si),
        );

        $destAddress = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);
        $runtime->memoryAccessor()->allocate($destAddress, safe: false);
        $runtime->memoryAccessor()->writeBySize($destAddress, $value, 8);

        $step = $runtime->memoryAccessor()->shouldDirectionFlag() ? -1 : 1;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->add(RegisterType::ESI, $step);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->add(RegisterType::EDI, $step);

        return ExecutionStatus::SUCCESS;
    }
}
