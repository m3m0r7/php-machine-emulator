<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86_64;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * POP instruction for 64-bit mode.
 *
 * In 64-bit mode, POP defaults to 64-bit operand size.
 * Opcodes 0x58-0x5F: POP r64
 *
 * With REX.B, can pop to R8-R15.
 */
class Pop64 implements InstructionInterface
{
    use Instructable64;

    public function opcodes(): array
    {
        return range(0x58, 0x5F);
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();

        // In non-64-bit mode, delegate to standard x86 pop
        if (!$cpu->isLongMode() || $cpu->isCompatibilityMode()) {
            return $this->instructionList->x86()
                ->getInstructionByOperationCode($opcode)
                ->process($runtime, $opcode);
        }

        // Get register code from opcode (0-7)
        $regCode = $opcode & 0b111;

        // Apply REX.B for R8-R15
        if ($cpu->rexB()) {
            $regCode |= 0b1000;
        }

        // Pop 64-bit value from stack
        $value = $this->pop64($runtime);

        // Write to register
        $regType = $this->getRegisterType64($regCode);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($regType, $value, 64);

        return ExecutionStatus::SUCCESS;
    }
}
