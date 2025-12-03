<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PopSeg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            0x07,
            0x17,
            0x1F,
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $seg = match ($opcode) {
            0x07 => RegisterType::ES,
            0x17 => RegisterType::SS,
            0x1F => RegisterType::DS,
        };

        $size = $runtime->context()->cpu()->operandSize();
        $espBefore = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(32);

        $value = $runtime->memoryAccessor()->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $runtime->memoryAccessor()->write16Bit($seg, $value);

        $espAfter = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(32);
        if ($espAfter !== $espBefore + ($size === 32 ? 4 : 2)) {
            $runtime->option()->logger()->debug(sprintf(
                'POP %s: ESP before=0x%08X after=0x%08X size=%d (unexpected!)',
                match($seg) { RegisterType::ES => 'ES', RegisterType::DS => 'DS', RegisterType::SS => 'SS', default => '??' },
                $espBefore, $espAfter, $size
            ));
        }

        return ExecutionStatus::SUCCESS;
    }
}
