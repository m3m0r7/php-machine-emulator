<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * JL/JNGE - Jump if Less (SF != OF)
 */
class Jl implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x7C]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $operand = $runtime
                ->memory()
            ->signedByte();

        $pos = $runtime
                ->memory()
            ->offset();

        $sf = $runtime->memoryAccessor()->shouldSignFlag();
        $of = $runtime->memoryAccessor()->shouldOverflowFlag();

        // JL: Jump if SF != OF
        if ($runtime->option()->shouldChangeOffset() && ($sf !== $of)) {
            $runtime
                ->memory()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
