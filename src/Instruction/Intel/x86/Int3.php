<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * INT3 (0xCC) - Breakpoint interrupt.
 * This is a one-byte instruction that triggers interrupt vector 3.
 */
class Int3 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xCC];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        // INT3 triggers interrupt vector 3 (breakpoint)
        // In a real debugger this would pause execution, but for emulation
        // we just log it and continue (or could trigger a halt for debugging)
        $runtime->option()->logger()->debug('INT3 breakpoint triggered');

        // For now, treat as a no-op to allow boot to continue
        // A full implementation would push flags/CS/IP and jump to IVT[3]
        return ExecutionStatus::SUCCESS;
    }
}
