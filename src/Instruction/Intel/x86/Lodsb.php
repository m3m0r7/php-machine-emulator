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

        // NOTE: Try to fetch from allocated memory because the DI register writes to it.
        $value = $runtime
            ->memoryAccessor()
            ->tryToFetch($si)
            ?->asLowBit();

        // NOTE: Looking for bits from disk
        if ($value === null) {
            $reader
                ->setOffset(
                    $runtime->addressMap()->getDisk()->entrypointOffset() + ($si - $runtime->addressMap()->getOrigin()),
                );
            $value = $reader->byte();
        }

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
            ->increment(RegisterType::ESI);

        return ExecutionStatus::SUCCESS;
    }
}
