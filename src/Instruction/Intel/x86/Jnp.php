<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * JNP/JPO - Jump if Not Parity / Jump if Parity Odd
 *
 * Opcode: 7B cb
 *
 * Jumps short if the Parity Flag (PF) is clear (PF=0).
 */
class Jnp implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x7B]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $operand = $runtime
                ->memory()
            ->signedByte();

        $pos = $runtime
                ->memory()
            ->offset();

        // JNP: Jump if Not Parity (PF=0)
        $pf = $runtime->memoryAccessor()->shouldParityFlag();
        $target = $pos + $operand;
        $runtime->option()->logger()->debug(sprintf('JNP: pos=0x%04X operand=0x%02X target=0x%04X PF=%s taken=%s', $pos, $operand & 0xFF, $target, $pf ? '1' : '0', !$pf ? 'YES' : 'NO'));

        if ($runtime->option()->shouldChangeOffset() && !$pf) {
            $runtime
                ->memory()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
