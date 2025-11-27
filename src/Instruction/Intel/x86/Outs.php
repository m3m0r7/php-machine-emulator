<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

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
        return [0x6E, 0x6F];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $isByte = $opcode === 0x6E;
        $width = $isByte ? 8 : $opSize;

        $port = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(16) & 0xFFFF;

        $this->assertIoPermission($runtime, $port, $width);
        if ($runtime->context()->cpu()->isProtectedMode()) {
            $cpl = $runtime->context()->cpu()->cpl();
            $iopl = $runtime->context()->cpu()->iopl();
            if ($cpl > $iopl) {
                throw new \PHPMachineEmulator\Exception\FaultException(0x0D, 0, 'OUTS privilege check failed');
            }
        }

        $count = $runtime->memoryAccessor()->shouldDirectionFlag() ? -1 : 1;
        $delta = $count * ($isByte ? 1 : ($opSize === 32 ? 4 : 2));

        $src = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($runtime->context()->cpu()->addressSize());
        $segment = $runtime->segmentOverride() ?? RegisterType::DS;
        $segBase = $runtime->memoryAccessor()->fetch($segment)->asByte();
        $linearAddr = ($segBase << 4) + $src;
        $value = match ($width) {
            8 => $this->readMemory8($runtime, $linearAddr),
            16 => $this->readMemory16($runtime, $linearAddr),
            32 => $this->readMemory32($runtime, $linearAddr),
            default => 0,
        };

        $this->writePort($runtime, $port, $value, $width);

        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize(RegisterType::ESI, $src + $delta, $runtime->context()->cpu()->addressSize());

        return ExecutionStatus::SUCCESS;
    }
}
