<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Ins implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        // 0x6C = INSB, 0x6D = INSW/INSD
        return $this->applyPrefixes([0x6C, 0x6D]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $opSize = $runtime->context()->cpu()->operandSize();
        $isByte = $opcode === 0x6C;
        $width = $isByte ? 8 : $opSize;

        $port = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(16) & 0xFFFF;

        // assertIoPermission handles both IOPL and I/O bitmap checks
        $this->assertIoPermission($runtime, $port, $width);

        $count = $runtime->memoryAccessor()->shouldDirectionFlag() ? -1 : 1;
        $delta = $count * ($isByte ? 1 : ($opSize === 32 ? 4 : 2));

        $value = $this->readPort($runtime, $port, $width);

        $dest = $runtime->memoryAccessor()->fetch(RegisterType::EDI)->asBytesBySize($runtime->context()->cpu()->addressSize());
        // INS always writes to ES:DI; segment overrides are ignored.
        $segment = RegisterType::ES;
        $linearAddr = $this->segmentOffsetAddress($runtime, $segment, $dest);
        if ($port === 0x170 || $port === 0x1F0) {
            $runtime->option()->logger()->debug(sprintf(
                'INS port=0x%04X seg=%s di=0x%04X linear=0x%05X',
                $port,
                $segment->name,
                $dest & 0xFFFF,
                $linearAddr & 0xFFFFF
            ));
        }
        match ($width) {
            8 => $this->writeMemory8($runtime, $linearAddr, $value),
            16 => $this->writeMemory16($runtime, $linearAddr, $value),
            32 => $this->writeMemory32($runtime, $linearAddr, $value),
            default => null,
        };

        $runtime->memoryAccessor()->writeBySize(RegisterType::EDI, $dest + $delta, $runtime->context()->cpu()->addressSize());

        return ExecutionStatus::SUCCESS;
    }
}
