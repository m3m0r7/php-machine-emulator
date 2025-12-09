<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * LEAVE - High Level Procedure Exit
 *
 * Releases the stack frame set up by an earlier ENTER instruction.
 * The LEAVE instruction copies the frame pointer (in the EBP register)
 * into the stack pointer register (ESP), which releases the stack space
 * allocated to the stack frame. The old frame pointer (the frame pointer
 * for the calling procedure that was saved by the ENTER instruction)
 * is then popped from the stack into the EBP register.
 */
class Leave implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xC9]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();
        $stackSize = $runtime->context()->cpu()->operandSize();

        // Set SP = BP (release local variable space)
        $bp = $ma->fetch(RegisterType::EBP)->asBytesBySize($stackSize);
        $ma->writeBySize(RegisterType::ESP, $bp, $stackSize);

        // Pop BP (restore caller's frame pointer)
        $oldBp = $ma->pop(RegisterType::ESP, $stackSize)->asBytesBySize($stackSize);
        $ma->writeBySize(RegisterType::EBP, $oldBp, $stackSize);

        return ExecutionStatus::SUCCESS;
    }
}
