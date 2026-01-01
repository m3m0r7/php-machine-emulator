<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jo implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x70]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $operand = $runtime->memory()->signedByte();
        $pos = $runtime->memory()->offset();

        // Jump if overflow flag is set
        if ($runtime->option()->shouldChangeOffset() && $runtime->memoryAccessor()->shouldOverflowFlag()) {
            $runtime->memory()->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
