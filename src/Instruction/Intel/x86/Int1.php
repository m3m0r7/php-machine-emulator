<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

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
        return [0xF1];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        // INT1/ICEBP triggers interrupt vector 1 (debug exception)
        $runtime->option()->logger()->debug('INT1/ICEBP debug trap triggered');

        // Raise interrupt vector 1 (debug exception)
        $returnIp = $runtime->memory()->offset();
        $intHandler = $this->instructionList->instructionList()[Int_::class] ?? null;
        if ($intHandler instanceof Int_) {
            $intHandler->raise($runtime, 1, $returnIp, null);
        }

        return ExecutionStatus::SUCCESS;
    }
}
