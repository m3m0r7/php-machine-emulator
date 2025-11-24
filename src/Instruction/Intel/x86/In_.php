<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class In_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE4, 0xE5, 0xEC, 0xED];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhanced = new EnhanceStreamReader($runtime->streamReader());

        $port = match ($opcode) {
            0xE4, 0xE5 => $enhanced->streamReader()->byte(),
            0xEC, 0xED => $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asByte() & 0xFFFF,
        };

        $value = $this->readPort($runtime, $port, $opcode === 0xE4 || $opcode === 0xEC ? 8 : 16);

        if ($opcode === 0xE4 || $opcode === 0xEC) {
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $value);
        } else {
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $value);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function readPort(RuntimeInterface $runtime, int $port, int $width): int
    {
        $runtime->option()->logger()->debug(sprintf('IN from port 0x%04X (%d-bit)', $port, $width));

        // Basic keyboard data port (0x60)
        if ($port === 0x60) {
            $byte = $runtime->option()->IO()->input()->byte();
            // normalize LF to CR to mimic BIOS keyboard handler
            if ($byte === 0x0A) {
                $byte = 0x0D;
            }
            return $byte;
        }

        // Keyboard status port: bit0 set when data available
        if ($port === 0x64) {
            return 0x01;
        }

        // PIT channels and control
        if (in_array($port, [0x40, 0x41, 0x42, 0x43], true)) {
            return 0;
        }

        // PIC master/slave status
        if (in_array($port, [0x20, 0x21, 0xA0, 0xA1], true)) {
            return 0;
        }

        // CMOS RTC ports
        if (in_array($port, [0x70, 0x71], true)) {
            return 0;
        }

        // COM1 data port: read last written value not tracked, return 0
        if ($port === 0x3F8) {
            return 0;
        }

        return 0;
    }
}
