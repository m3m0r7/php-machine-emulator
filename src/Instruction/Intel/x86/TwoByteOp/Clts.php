<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * CLTS (0x0F 0x06)
 * Clear Task-Switched Flag in CR0.
 */
class Clts implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0x06]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $cr0 = $runtime->memoryAccessor()->readControlRegister(0);
        $cr0 &= ~(1 << 3); // clear TS
        $runtime->memoryAccessor()->writeControlRegister(0, $cr0 | 0x22); // keep MP/NE set

        return ExecutionStatus::SUCCESS;
    }
}
