<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

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
        return $this->applyPrefixes([0xCC]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        // INT3 triggers interrupt vector 3 (breakpoint)
        $runtime->option()->logger()->debug('INT3 breakpoint triggered');

        // Raise interrupt vector 3 (breakpoint exception)
        $returnIp = $runtime->memory()->offset();
        try {
            $handler = $this->instructionList->findInstruction(0xCD);
        } catch (\Throwable) {
            return ExecutionStatus::SUCCESS;
        }

        if ($handler instanceof Int_) {
            $handler->raiseSoftware($runtime, 3, $returnIp, null);
        }

        return ExecutionStatus::SUCCESS;
    }
}
