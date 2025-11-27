<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Popf implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x9D];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $size = $runtime->runtimeOption()->context()->operandSize();
        $flags = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);

        $ma->setCarryFlag(($flags & 0x1) !== 0);
        $ma->updateFlags(($flags & (1 << 6)) ? 0 : 1, $size); // zero flag
        $ma->setParityFlag(($flags & (1 << 2)) !== 0);
        $ma->setSignFlag(($flags & (1 << 7)) !== 0);
        $ma->setOverflowFlag(($flags & (1 << 11)) !== 0);
        $ma->setDirectionFlag(($flags & (1 << 10)) !== 0);

        if ($runtime->runtimeOption()->context()->isProtectedMode()) {
            $cpl = $runtime->runtimeOption()->context()->cpl();
            $iopl = $runtime->runtimeOption()->context()->iopl();

            // IF change allowed only if CPL <= IOPL; otherwise preserve current IF.
            $newIf = ($flags & (1 << 9)) !== 0;
            if ($cpl <= $iopl) {
                $ma->setInterruptFlag($newIf);
            }

            // IOPL change only at CPL==0.
            if ($cpl === 0) {
                $runtime->runtimeOption()->context()->setIopl(($flags >> 12) & 0x3);
            }

            // NT change allowed only at CPL==0, otherwise preserve.
            if ($cpl === 0) {
                $runtime->runtimeOption()->context()->setNt(($flags & (1 << 14)) !== 0);
            }
        } else {
            $ma->setInterruptFlag(($flags & (1 << 9)) !== 0);
        }

        return ExecutionStatus::SUCCESS;
    }
}
