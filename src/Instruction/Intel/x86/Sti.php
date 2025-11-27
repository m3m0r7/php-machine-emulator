<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Sti extends Nop
{
    public function opcodes(): array
    {
        return [0xFB];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        if ($runtime->runtimeOption()->context()->isProtectedMode()) {
            $cpl = $runtime->runtimeOption()->context()->cpl();
            $iopl = $runtime->runtimeOption()->context()->iopl();
            if ($cpl > $iopl) {
                throw new \PHPMachineEmulator\Exception\FaultException(0x0D, 0, 'STI privilege check failed');
            }
        }

        $runtime->memoryAccessor()->setInterruptFlag(true);
        // STI defers interrupt recognition until after the next instruction.
        $runtime->runtimeOption()->context()->blockInterruptDelivery(1);
        return ExecutionStatus::SUCCESS;
    }
}
