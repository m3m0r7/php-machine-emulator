<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * INTO - Interrupt on Overflow
 *
 * Opcode: CE
 *
 * Generates a software interrupt if the Overflow Flag (OF) is set.
 * If OF is 1, generates an INT 4 (overflow exception).
 * If OF is 0, execution continues with the next instruction.
 *
 * Note: This instruction is invalid in 64-bit mode.
 */
class Into implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xCE];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        // Check if we're in 64-bit mode (INTO is invalid in 64-bit mode)
        $cpu = $runtime->context()->cpu();
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            // #UD in 64-bit mode
            $runtime->option()->logger()->warning('INTO: Invalid in 64-bit mode');
            return ExecutionStatus::SUCCESS;
        }

        // Check the Overflow Flag
        if ($runtime->memoryAccessor()->shouldOverflowFlag()) {
            // Trigger INT 4 (overflow exception)
            $runtime->option()->logger()->debug('INTO: Overflow detected, triggering INT 4');

            // Get the Int_ instruction handler and raise INT 4
            $intInstruction = $this->instructionList->instructionList()[Int_::class] ?? null;
            if ($intInstruction instanceof Int_) {
                $returnIp = $runtime->memory()->offset();
                $intInstruction->raise($runtime, 4, $returnIp);
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
