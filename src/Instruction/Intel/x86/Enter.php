<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * ENTER - Make Stack Frame for Procedure Parameters
 *
 * Creates a stack frame for a procedure. The first operand (imm16) specifies
 * the size of the stack frame (local variable space). The second operand (imm8)
 * specifies the lexical nesting level (0 to 31) of the procedure.
 */
class Enter implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xC8];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $memory = $runtime->memory();
        $ma = $runtime->memoryAccessor();

        // Read operands: imm16 (size) and imm8 (nesting level)
        $size = $memory->short();
        $nestingLevel = $memory->byte() & 0x1F; // Only 5 bits (0-31)

        $addressSize = $runtime->context()->cpu()->addressSize();
        $stackSize = $runtime->context()->cpu()->operandSize();

        // Get current BP and SP
        $bp = $ma->fetch(RegisterType::EBP)->asBytesBySize($stackSize);
        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($stackSize);

        // Push BP
        $ma->push(RegisterType::ESP, $bp, $stackSize);
        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($stackSize);

        // Frame pointer - save SP after pushing BP
        $framePtr = $sp;

        // Handle nested procedures (levels 1-31)
        if ($nestingLevel > 0) {
            for ($i = 1; $i < $nestingLevel; $i++) {
                // Push previous frame pointers
                $bp = ($stackSize === 32)
                    ? ($bp - 4) & 0xFFFFFFFF
                    : ($bp - 2) & 0xFFFF;

                // Read the display element
                $displayElement = $this->readFromStack($runtime, $bp, $stackSize);
                $ma->push(RegisterType::ESP, $displayElement, $stackSize);
            }
            // Push current frame pointer
            $ma->push(RegisterType::ESP, $framePtr, $stackSize);
        }

        // Set BP to frame pointer
        $ma->enableUpdateFlags(false)->writeBySize(RegisterType::EBP, $framePtr, $stackSize);

        // Allocate local variable space
        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($stackSize);
        $newSp = ($stackSize === 32)
            ? ($sp - $size) & 0xFFFFFFFF
            : ($sp - $size) & 0xFFFF;
        $ma->enableUpdateFlags(false)->writeBySize(RegisterType::ESP, $newSp, $stackSize);

        return ExecutionStatus::SUCCESS;
    }

    private function readFromStack(RuntimeInterface $runtime, int $address, int $size): int
    {
        $ma = $runtime->memoryAccessor();
        $ss = $ma->fetch(RegisterType::SS)->asByte();

        // Calculate linear address
        if ($runtime->context()->cpu()->isProtectedMode()) {
            $linear = $address;
        } else {
            $linear = (($ss << 4) + $address) & 0xFFFFF;
        }

        $bytes = intdiv($size, 8);
        $value = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $byte = $ma->readRawByte($linear + $i) ?? 0;
            $value |= ($byte << ($i * 8));
        }

        return $value;
    }
}
