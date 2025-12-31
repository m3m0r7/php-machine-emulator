<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PopSeg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([
            0x07,
            0x17,
            0x1F,
        ], [PrefixClass::Operand]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $seg = match ($opcode) {
            0x07 => RegisterType::ES,
            0x17 => RegisterType::SS,
            0x1F => RegisterType::DS,
        };

        // Segment pops are always 16-bit, regardless of operand size overrides.
        $espBefore = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(32);

        $value = $runtime->memoryAccessor()->pop(RegisterType::ESP, 16)->asBytesBySize(16);
        $cpu = $runtime->context()->cpu();

        // In protected mode, cache descriptor for Unreal Mode support.
        if ($cpu->isProtectedMode() && $value !== 0) {
            $descriptor = $this->readSegmentDescriptor($runtime, $value);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $cpu->cacheSegmentDescriptor($seg, $descriptor);
            }
        }

        $runtime->memoryAccessor()->write16Bit($seg, $value);
        if ($seg === RegisterType::SS) {
            // POP SS blocks interrupts for the following instruction.
            $runtime->context()->cpu()->blockInterruptDelivery(1);
        }

        // In real mode, loading a segment resets its hidden cache to real-mode values.
        if (!$cpu->isProtectedMode()) {
            $cpu->cacheSegmentDescriptor($seg, [
                'base' => (($value << 4) & 0xFFFFF),
                'limit' => 0xFFFF,
                'present' => true,
                'type' => 0,
                'system' => false,
                'executable' => false,
                'dpl' => 0,
                'default' => 16,
            ]);
        }

        $espAfter = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(32);
        if ($espAfter !== $espBefore + 2) {
            $runtime->option()->logger()->debug(sprintf(
                'POP %s: ESP before=0x%08X after=0x%08X size=%d (unexpected!)',
                match($seg) { RegisterType::ES => 'ES', RegisterType::DS => 'DS', RegisterType::SS => 'SS', default => '??' },
                $espBefore, $espAfter, 16
            ));
        }

        return ExecutionStatus::SUCCESS;
    }
}
