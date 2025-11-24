<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Sahf implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x9E];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $flags = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();

        $runtime->memoryAccessor()->setCarryFlag(($flags & 0x1) !== 0);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->updateFlags(($flags & (1 << 6)) ? 0 : 1, 8);
        $runtime->memoryAccessor()->enableUpdateFlags(false);

        return ExecutionStatus::SUCCESS;
    }
}
