<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Address-size override (0x67). Currently delegates to the next opcode without changing addressing semantics.
 */
class AddressSizePrefix implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x67];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $runtime->context()->cpu()->setAddressSizeOverride(true);
        return ExecutionStatus::CONTINUE;
    }
}
