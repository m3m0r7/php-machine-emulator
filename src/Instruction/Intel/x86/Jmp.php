<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jmp implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE9];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand1 = $runtime
            ->streamReader()
            ->byte();

        $operand2 = $runtime
            ->streamReader()
            ->byte();

        // NOTE: Calculate included negative offset
        $offset = ($operand2 << 8) + $operand1;
        $offset = $offset >= 0x8000
            ? $offset - 0x10000
            : $offset;

        // NOTE: Add current origin
        $offset += $runtime->addressMap()->getOrigin();

        $pos = $runtime
            ->streamReader()
            ->offset();

        $disk = $runtime
            ->addressMap()
            ->getDiskByAddress(
                // NOTE: Adjustment offset that is to negate entrypoint
                //       because the instruction is including sector size when embedding operands.
                $pos + $offset - $runtime->addressMap()->getDisk()->entrypointOffset(),
            );

        if ($disk !== null) {
            $runtime
                ->streamReader()
                ->setOffset($disk->offset());
            return ExecutionStatus::SUCCESS;
        }

        $runtime
            ->streamReader()
            ->setOffset($pos + $offset);

        return ExecutionStatus::SUCCESS;
    }
}
