<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86_64;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * PUSH instruction for 64-bit mode.
 *
 * In 64-bit mode, PUSH defaults to 64-bit operand size.
 * Opcodes 0x50-0x57: PUSH r64
 *
 * With REX.B, can push R8-R15.
 */
class Push64 implements InstructionInterface
{
    use Instructable64;

    public function opcodes(): array
    {
        return range(0x50, 0x57);
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();

        // In non-64-bit mode, delegate to standard x86 push
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

        // Read 64-bit value from register
        $regType = $this->getRegisterType64($regCode);
        $value = $runtime->memoryAccessor()->fetch($regType)->asBytesBySize(64);

        // Push onto stack
        $this->push64($runtime, $value);

        return ExecutionStatus::SUCCESS;
    }
}
