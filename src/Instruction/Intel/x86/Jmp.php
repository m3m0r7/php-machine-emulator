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

        $offset = $runtime->context()->cpu()->operandSize() === 32
            ? $enhancedStreamReader->signedDword()
            : $enhancedStreamReader->signedShort();

        $pos = $runtime
            ->streamReader()
            ->offset();

        // In protected mode, don't add origin - use linear addresses directly
        if (!$runtime->context()->cpu()->isProtectedMode()) {
            // NOTE: Add current origin only in real mode
            $offset += $runtime->addressMap()->getOrigin();
        }

        $target = $pos + $offset;

        $runtime->option()->logger()->debug(sprintf(
            'JMP: IP before=0x%05X, offset=0x%08X, target=0x%05X, operandSize=%d, protectedMode=%d',
            $pos, $offset, $target, $runtime->context()->cpu()->operandSize(), $runtime->context()->cpu()->isProtectedMode() ? 1 : 0
        ));

        if (!$runtime->option()->shouldChangeOffset()) {
            return ExecutionStatus::SUCCESS;
        }

        // In protected mode, use linear addresses directly
        if ($runtime->context()->cpu()->isProtectedMode()) {
            $runtime->option()->logger()->debug(sprintf(
                'JMP: protected mode, setting offset to 0x%05X',
                $target
            ));
            $runtime
                ->streamReader()
                ->setOffset($target);
            return ExecutionStatus::SUCCESS;
        }

        $disk = $runtime
            ->addressMap()
            ->getDiskByAddress(
                // NOTE: Adjustment offset that is to negate entrypoint
                //       because the instruction is including sector size when embedding operands.
                $target - $runtime->addressMap()->getDisk()->entrypointOffset(),
            );

        if ($disk !== null) {
            $runtime->option()->logger()->debug(sprintf(
                'JMP: using disk offset=0x%05X',
                $disk->offset()
            ));
            $runtime
                ->streamReader()
                ->setOffset($disk->offset());
            return ExecutionStatus::SUCCESS;
        }

        $runtime->option()->logger()->debug(sprintf(
            'JMP: no disk, setting offset to 0x%05X',
            $target
        ));
        $runtime
            ->streamReader()
            ->setOffset($target);

        return ExecutionStatus::SUCCESS;
    }
}
