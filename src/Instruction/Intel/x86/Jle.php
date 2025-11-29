<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * JLE/JNG - Jump if Less or Equal (SF != OF OR ZF=1)
 */
class Jle implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x7E];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
                ->memory()
            ->signedByte();

        $pos = $runtime
                ->memory()
            ->offset();

        $sf = $runtime->memoryAccessor()->shouldSignFlag();
        $of = $runtime->memoryAccessor()->shouldOverflowFlag();
        $zf = $runtime->memoryAccessor()->shouldZeroFlag();

        // JLE: Jump if SF != OF OR ZF=1
        $taken = ($sf !== $of) || $zf;

        if ($runtime->option()->shouldChangeOffset() && $taken) {
            $runtime
                ->memory()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
