<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Cli extends Nop
{
    public function opcodes(): array
    {
        return [0xFA];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        if ($runtime->runtimeOption()->context()->isProtectedMode()) {
            $cpl = $runtime->runtimeOption()->context()->cpl();
            $iopl = $runtime->runtimeOption()->context()->iopl();
            if ($cpl > $iopl) {
                throw new \PHPMachineEmulator\Exception\FaultException(0x0D, 0, 'CLI privilege check failed');
            }
        }

        $runtime->memoryAccessor()->setInterruptFlag(false);
        // Clear any pending STI deferral.
        $runtime->runtimeOption()->context()->blockInterruptDelivery(0);
        return ExecutionStatus::SUCCESS;
    }
}
