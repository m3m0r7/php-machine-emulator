<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * INT1 / ICEBP (0xF1) - In-Circuit Emulator Breakpoint
 *
 * This is an undocumented one-byte instruction that triggers interrupt vector 1
 * (single-step/debug exception). It's used by ICE (In-Circuit Emulator) for
 * debugging purposes.
 *
 * In modern x86, this generates a #DB (debug exception, vector 1).
 */
class Int1 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xF1]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        // INT1/ICEBP triggers interrupt vector 1 (debug exception)
        $runtime->option()->logger()->debug('INT1/ICEBP debug trap triggered');

        // Raise interrupt vector 1 (debug exception)
        $returnIp = $runtime->memory()->offset();
        try {
            $handler = $this->instructionList->findInstruction(0xCD);
        } catch (\Throwable) {
            return ExecutionStatus::SUCCESS;
        }

        if ($handler instanceof Int_) {
            $handler->raiseSoftware($runtime, 1, $returnIp, null);
        }

        return ExecutionStatus::SUCCESS;
    }
}
