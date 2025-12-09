<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * JCXZ/JECXZ - Jump if CX/ECX is Zero
 *
 * Opcode: 0xE3
 *
 * Jumps to the specified address if CX (or ECX in 32-bit mode) is zero.
 */
class Jcxz implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xE3]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $operand = $runtime->memory()->signedByte();
        $pos = $runtime->memory()->offset();

        // Check CX or ECX depending on address size
        $addressSize = $runtime->context()->cpu()->addressSize();
        $cx = $runtime->memoryAccessor()->fetch(RegisterType::ECX);
        $count = $addressSize === 32 ? $cx->asBytesBySize(32) : $cx->asBytesBySize(16);

        // JCXZ: Jump if CX/ECX is zero
        if ($runtime->option()->shouldChangeOffset() && $count === 0) {
            $runtime->memory()->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
