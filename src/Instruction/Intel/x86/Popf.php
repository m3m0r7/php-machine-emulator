<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Popf implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x9D]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();
        $size = $runtime->context()->cpu()->operandSize();
        $flags = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);

        // Restore flags directly without using updateFlags
        $ma->setCarryFlag(($flags & 0x1) !== 0);
        $ma->setParityFlag(($flags & (1 << 2)) !== 0);
        $ma->setAuxiliaryCarryFlag(($flags & (1 << 4)) !== 0);
        $ma->setZeroFlag(($flags & (1 << 6)) !== 0);
        $ma->setSignFlag(($flags & (1 << 7)) !== 0);
        $ma->setOverflowFlag(($flags & (1 << 11)) !== 0);
        $ma->setDirectionFlag(($flags & (1 << 10)) !== 0);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $cpl = $runtime->context()->cpu()->cpl();
            $iopl = $runtime->context()->cpu()->iopl();

            // IF change allowed only if CPL <= IOPL; otherwise preserve current IF.
            $newIf = ($flags & (1 << 9)) !== 0;
            if ($cpl <= $iopl) {
                $ma->setInterruptFlag($newIf);
            }

            // IOPL change only at CPL==0.
            if ($cpl === 0) {
                $runtime->context()->cpu()->setIopl(($flags >> 12) & 0x3);
            }

            // NT change allowed only at CPL==0, otherwise preserve.
            if ($cpl === 0) {
                $runtime->context()->cpu()->setNt(($flags & (1 << 14)) !== 0);
            }
        } else {
            $ma->setInterruptFlag(($flags & (1 << 9)) !== 0);
        }

        return ExecutionStatus::SUCCESS;
    }
}
