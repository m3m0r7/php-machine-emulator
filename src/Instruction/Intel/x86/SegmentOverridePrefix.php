<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class SegmentOverridePrefix implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return array_keys($this->map());
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $segment = $this->map()[$opcode];
        $runtime->setSegmentOverride($segment);

        // Execute the following opcode with the override applied.
        $nextOpcode = $runtime->memory()->byte();
        return $runtime->execute($nextOpcode);
    }

    private function map(): array
    {
        return [
            0x26 => RegisterType::ES,
            0x2E => RegisterType::CS,
            0x36 => RegisterType::SS,
            0x3E => RegisterType::DS,
            0x64 => RegisterType::FS,
            0x65 => RegisterType::GS,
        ];
    }
}
