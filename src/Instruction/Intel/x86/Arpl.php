<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * ARPL r/m16, r16 (opcode 0x63).
 *
 * Adjusts the Requested Privilege Level (RPL) of the destination selector.
 * If dest.RPL < src.RPL, dest.RPL is set to src.RPL and ZF=1.
 * Otherwise dest is unchanged and ZF=0.
 *
 * This instruction is 16-bit only; in long mode the opcode is repurposed
 * for MOVSXD and is handled by the x86_64 instruction list.
 */
class Arpl implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x63]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        // ARPL always works on 16-bit operands even when the default operand size is 32-bit.
        $dest = $this->readRm16($runtime, $reader, $modRegRM) & 0xFFFF;
        $src = $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), 16) & 0xFFFF;

        $destRpl = $dest & 0x3;
        $srcRpl = $src & 0x3;

        $ma = $runtime->memoryAccessor();

        if ($destRpl < $srcRpl) {
            $newVal = ($dest & ~0x3) | $srcRpl;
            $this->writeRm($runtime, $reader, $modRegRM, $newVal, 16);
            $ma->setZeroFlag(true);
        } else {
            $ma->setZeroFlag(false);
        }

        // ARPL leaves other flags undefined; keep them unchanged.
        return ExecutionStatus::SUCCESS;
    }
}
