<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Out_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE6, 0xE7, 0xEE, 0xEF];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhanced = new EnhanceStreamReader($runtime->streamReader());

        $port = match ($opcode) {
            0xE6, 0xE7 => $enhanced->streamReader()->byte(),
            0xEE, 0xEF => $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asByte() & 0xFFFF,
        };

        $value = ($opcode === 0xE6 || $opcode === 0xEE)
            ? $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit()
            : $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();

        $this->writePort($runtime, $port, $value, ($opcode === 0xE6 || $opcode === 0xEE) ? 8 : 16);

        return ExecutionStatus::SUCCESS;
    }

    private function writePort(RuntimeInterface $runtime, int $port, int $value, int $width): void
    {
        $runtime->option()->logger()->debug(sprintf('OUT to port 0x%04X value 0x%X (%d-bit)', $port, $value, $width));

        // COM1: treat as serial output -> write low byte to output
        if ($port === 0x3F8) {
            $runtime->option()->IO()->output()->write(chr($value & 0xFF));
            return;
        }

        // Keyboard controller commands ignored for now
        if (in_array($port, [0x60, 0x64], true)) {
            return;
        }

        // PIT or speaker ports ignored safely
        if (in_array($port, [0x40, 0x41, 0x42, 0x43], true)) {
            return;
        }

        // PIC master/slave commands or masks: ignore
        if (in_array($port, [0x20, 0x21, 0xA0, 0xA1], true)) {
            return;
        }

        // CMOS RTC ports ignored
        if (in_array($port, [0x70, 0x71], true)) {
            return;
        }
    }
}
