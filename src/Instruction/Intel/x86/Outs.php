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
        $opSize = $runtime->runtimeOption()->context()->operandSize();
        $isByte = $opcode === 0x6E;
        $width = $isByte ? 8 : $opSize;

        $port = $runtime->memoryAccessor()->fetch(RegisterType::DX)->asByte() & 0xFFFF;

        $this->assertIoPermission($runtime, $port, $width);
        if ($runtime->runtimeOption()->context()->isProtectedMode()) {
            $cpl = $runtime->runtimeOption()->context()->cpl();
            $iopl = $runtime->runtimeOption()->context()->iopl();
            if ($cpl > $iopl) {
                throw new \PHPMachineEmulator\Exception\FaultException(0x0D, 0, 'OUTS privilege check failed');
            }
        }

        $count = $runtime->memoryAccessor()->shouldDirectionFlag() ? -1 : 1;
        $delta = $count * ($isByte ? 1 : ($opSize === 32 ? 4 : 2));

        $src = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize($runtime->runtimeOption()->context()->addressSize());
        $value = $this->readBySize($runtime, $src, $width, $runtime->segmentOverride() ?? RegisterType::DS);

        $this->writePort($runtime, $port, $value, $width);

        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize(RegisterType::ESI, $src + $delta, $runtime->runtimeOption()->context()->addressSize());

        return ExecutionStatus::SUCCESS;
    }
}
