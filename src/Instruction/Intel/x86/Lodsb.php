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
        $reader = $runtime->streamReader()->proxy();

        $si = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::ESI)
            ->asByte();

        $reader
            ->setOffset($si - $runtime->runtimeOption()->entrypoint());

        $runtime->memoryAccessor()
            ->writeToLowBit(
                RegisterType::EAX,
                $reader->byte(),
            );

        // TODO: apply DF flag
        $runtime
            ->memoryAccessor()
            ->increment(RegisterType::ESI);

        return ExecutionStatus::SUCCESS;
    }
}
