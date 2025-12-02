<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * RDTSC (0x0F 0x31)
 * Read Time-Stamp Counter.
 */
class Rdtsc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [[0x0F, 0x31]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        // Use host microtime as a monotonic-ish counter.
        $tsc = (int) (microtime(true) * 1_000_000);
        $low = $tsc & 0xFFFFFFFF;
        $high = ($tsc >> 32) & 0xFFFFFFFF;

        $this->writeRegisterBySize($runtime, RegisterType::EAX, $low, 32);
        $this->writeRegisterBySize($runtime, RegisterType::EDX, $high, 32);

        return ExecutionStatus::SUCCESS;
    }
}
