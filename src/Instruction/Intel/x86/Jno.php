<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jno implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x71];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        // JNO: Jump if No Overflow (OF=0)
        $runtime->option()->logger()->debug(sprintf(
            'JNO: pos=0x%05X, operand=%d, OF=%d, target=0x%05X',
            $pos, $operand, $runtime->memoryAccessor()->shouldOverflowFlag() ? 1 : 0, $pos + $operand
        ));
        if ($runtime->option()->shouldChangeOffset() && !$runtime->memoryAccessor()->shouldOverflowFlag()) {
            $runtime
                ->streamReader()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
