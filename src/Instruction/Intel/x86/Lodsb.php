<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Lodsb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xAC];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asByte();
        $segment = $runtime->segmentOverride() ?? RegisterType::DS;

        $value = $this->readMemory8(
            $runtime,
            $this->segmentOffsetAddress($runtime, $segment, $si),
        );

        $runtime->memoryAccessor()
            ->enableUpdateFlags(false)
            ->writeToLowBit(
                RegisterType::EAX,
                $value,
            );

        // TODO: apply DF flag
        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->add(RegisterType::ESI, $runtime->memoryAccessor()->shouldDirectionFlag() ? -1 : 1);

        return ExecutionStatus::SUCCESS;
    }
}
