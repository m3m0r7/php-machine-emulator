<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Xor_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        // Handled by XorRegRm (0x30-0x33). Disable this legacy handler.
        return [];
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        throw new ExecutionException('Xor_ handler should not be invoked (delegated to XorRegRm)');
    }
}
