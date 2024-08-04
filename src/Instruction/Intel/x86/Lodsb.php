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

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $reader = $runtime->streamReader()->proxy();

        $si = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::ESI)
            ->asByte();

        $reader
            ->setOffset($si - $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asByte());

        $runtime->memoryAccessor()
            ->write(
                RegisterType::EAX,
                $reader->byte(),
            );

        $runtime
            ->memoryAccessor()
            ->increment(RegisterType::ESI);

        return ExecutionStatus::SUCCESS;
    }
}
