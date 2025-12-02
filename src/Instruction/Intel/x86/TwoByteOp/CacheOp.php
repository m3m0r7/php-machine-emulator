<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Cache operations (0x0F 0x08 / 0x0F 0x09)
 * INVD, WBINVD - treated as no-ops
 */
class CacheOp implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            [0x0F, 0x08], // INVD
            [0x0F, 0x09], // WBINVD
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        // No-op - no cache to invalidate
        return ExecutionStatus::SUCCESS;
    }
}
