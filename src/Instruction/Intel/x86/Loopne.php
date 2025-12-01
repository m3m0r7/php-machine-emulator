<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * LOOPNE/LOOPNZ - Loop while Not Equal/Not Zero (0xE0)
 * Decrements CX/ECX and jumps if CX/ECX != 0 AND ZF == 0
 */
class Loopne implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE0];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->memory()
            ->signedByte();

        $pos = $runtime
            ->memory()
            ->offset();

        // LOOPNE decrements ECX first, then checks if non-zero AND ZF==0
        $size = $runtime->context()->cpu()->addressSize();
        $counter = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::ECX)
            ->asBytesBySize($size);

        $counter = ($counter - 1) & ($size === 32 ? 0xFFFFFFFF : 0xFFFF);

        // Write decremented value back
        $runtime->memoryAccessor()
            ->writeBySize(RegisterType::ECX, $counter, $size);

        $zf = $runtime->memoryAccessor()->shouldZeroFlag();
        $target = $pos + $operand;

        $runtime->option()->logger()->debug(sprintf('LOOPNE: counter=%d, ZF=%d, operand=%d, pos=0x%X, target=0x%X',
            $counter, $zf ? 1 : 0, $operand, $pos, $target));

        // Jump if counter is non-zero AND ZF is clear
        if ($counter === 0 || $zf) {
            return ExecutionStatus::SUCCESS;
        }

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime
                ->memory()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
