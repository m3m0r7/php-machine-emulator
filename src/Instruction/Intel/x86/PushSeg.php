<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PushSeg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x06, 0x0E, 0x16, 0x1E]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $seg = match ($opcode) {
            0x06 => RegisterType::ES,
            0x0E => RegisterType::CS,
            0x16 => RegisterType::SS,
            0x1E => RegisterType::DS,
        };

        // Segment registers are always pushed as 16-bit values, regardless of operand size.
        $value = $runtime->memoryAccessor()->fetch($seg)->asByte();
        $runtime->memoryAccessor()->push(RegisterType::ESP, $value, 16);

        return ExecutionStatus::SUCCESS;
    }
}
