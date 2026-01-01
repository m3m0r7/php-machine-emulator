<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Lahf implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x9F]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();

        // LAHF loads AH with lower 8 bits of FLAGS:
        // Bit 7: SF, Bit 6: ZF, Bit 4: AF, Bit 2: PF, Bit 0: CF
        // Bit 1 is always 1, Bits 3 and 5 are always 0
        $flags =
            ($ma->shouldCarryFlag() ? 0x01 : 0) |       // bit 0: CF
            0x02 |                                       // bit 1: always 1
            ($ma->shouldParityFlag() ? 0x04 : 0) |       // bit 2: PF
            ($ma->shouldAuxiliaryCarryFlag() ? 0x10 : 0) | // bit 4: AF
            ($ma->shouldZeroFlag() ? 0x40 : 0) |         // bit 6: ZF
            ($ma->shouldSignFlag() ? 0x80 : 0);          // bit 7: SF

        // Write to AH (high byte of AX), not AL
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, $flags);

        return ExecutionStatus::SUCCESS;
    }
}
