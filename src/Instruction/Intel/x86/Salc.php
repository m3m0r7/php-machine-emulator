<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * SALC/SETALC - Set AL from Carry Flag (undocumented)
 *
 * Opcode: 0xD6
 *
 * Sets AL to 0xFF if CF=1, or 0x00 if CF=0.
 * This is an undocumented instruction but widely used.
 * Not available in 64-bit mode.
 */
class Salc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xD6]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $cf = $runtime->memoryAccessor()->shouldCarryFlag();
        $value = $cf ? 0xFF : 0x00;

        $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $value);

        return ExecutionStatus::SUCCESS;
    }
}
