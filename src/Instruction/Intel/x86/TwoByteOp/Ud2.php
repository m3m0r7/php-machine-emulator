<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * UD2 (0x0F 0x0B)
 * Undefined instruction.
 */
class Ud2 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [[0x0F, 0x0B]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        throw new FaultException(6, 0, 'UD2');
    }
}
