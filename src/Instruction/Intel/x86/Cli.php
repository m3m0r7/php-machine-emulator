<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Cli extends Nop
{
    public function opcodes(): array
    {
        return $this->applyPrefixes([0xFA]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        if ($runtime->context()->cpu()->isProtectedMode()) {
            $cpl = $runtime->context()->cpu()->cpl();
            $iopl = $runtime->context()->cpu()->iopl();
            if ($cpl > $iopl) {
                throw new \PHPMachineEmulator\Exception\FaultException(0x0D, 0, 'CLI privilege check failed');
            }
        }

        $runtime->memoryAccessor()->setInterruptFlag(false);
        // Clear any pending STI deferral.
        $runtime->context()->cpu()->blockInterruptDelivery(0);
        return ExecutionStatus::SUCCESS;
    }
}
