<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Outs implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        // 0x6E = OUTSB, 0x6F = OUTSW/OUTSD
        return $this->applyPrefixes([0x6E, 0x6F]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $opSize = $runtime->context()->cpu()->operandSize();
        $isByte = $opcode === 0x6E;
        $width = $isByte ? 8 : $opSize;

        $port = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(16) & 0xFFFF;

        // assertIoPermission handles both IOPL and I/O bitmap checks
        $this->assertIoPermission($runtime, $port, $width);

        $count = $runtime->memoryAccessor()->shouldDirectionFlag() ? -1 : 1;
        $delta = $count * ($isByte ? 1 : ($opSize === 32 ? 4 : 2));

        $src = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($runtime->context()->cpu()->addressSize());
        $segment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;
        $linearAddr = $this->segmentOffsetAddress($runtime, $segment, $src);
        $value = match ($width) {
            8 => $this->readMemory8($runtime, $linearAddr),
            16 => $this->readMemory16($runtime, $linearAddr),
            32 => $this->readMemory32($runtime, $linearAddr),
            default => 0,
        };

        $this->writePort($runtime, $port, $value, $width);

        $runtime->memoryAccessor()->writeBySize(RegisterType::ESI, $src + $delta, $runtime->context()->cpu()->addressSize());

        return ExecutionStatus::SUCCESS;
    }
}
