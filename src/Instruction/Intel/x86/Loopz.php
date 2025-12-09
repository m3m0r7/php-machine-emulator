<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * LOOPZ/LOOPE - Loop While Zero/Equal
 *
 * Opcode: E1 cb
 *
 * Decrements ECX/CX, then jumps to the target address if ECX/CX is non-zero
 * AND the Zero Flag (ZF) is set.
 *
 * Operation:
 * ECX ← ECX - 1;
 * IF (ECX != 0) AND (ZF = 1) THEN
 *     Jump to target
 * FI;
 */
class Loopz implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xE1]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $operand = $runtime->memory()->signedByte();
        $pos = $runtime->memory()->offset();

        // LOOPZ decrements ECX/CX first
        $size = $runtime->context()->cpu()->addressSize();
        $counter = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::ECX)
            ->asBytesBySize($size);

        $counter = ($counter - 1) & ($size === 32 ? 0xFFFFFFFF : 0xFFFF);

        // Write decremented value back
        $runtime->memoryAccessor()
            ->writeBySize(RegisterType::ECX, $counter, $size);

        // Jump if counter is non-zero AND ZF is set
        if ($counter !== 0 && $runtime->memoryAccessor()->shouldZeroFlag()) {
            if ($runtime->option()->shouldChangeOffset()) {
                $runtime->memory()->setOffset($pos + $operand);
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
