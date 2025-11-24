<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
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
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());

        $offset = $runtime->runtimeOption()->context()->operandSize() === 32
            ? $enhancedStreamReader->signedDword()
            : $enhancedStreamReader->signedShort();

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

        if (!$runtime->option()->shouldChangeOffset()) {
            return ExecutionStatus::SUCCESS;
        }

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
