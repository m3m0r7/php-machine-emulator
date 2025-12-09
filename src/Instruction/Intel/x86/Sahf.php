<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Sahf implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x9E]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        // SAHF stores AH into lower 8 bits of FLAGS
        // Read from AH (high byte of AX), not AL
        $flags = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asHighBit();
        $ma = $runtime->memoryAccessor();

        // Restore flags from AH:
        // Bit 7: SF, Bit 6: ZF, Bit 4: AF, Bit 2: PF, Bit 0: CF
        $ma->setCarryFlag(($flags & 0x01) !== 0);        // bit 0: CF
        $ma->setParityFlag(($flags & 0x04) !== 0);       // bit 2: PF
        $ma->setAuxiliaryCarryFlag(($flags & 0x10) !== 0); // bit 4: AF
        $ma->setZeroFlag(($flags & 0x40) !== 0);         // bit 6: ZF
        $ma->setSignFlag(($flags & 0x80) !== 0);         // bit 7: SF

        return ExecutionStatus::SUCCESS;
    }
}
