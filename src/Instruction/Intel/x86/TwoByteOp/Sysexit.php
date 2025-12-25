<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * SYSEXIT (0x0F 0x35)
 * Fast return from system call.
 */
class Sysexit implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0x35]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        if ($runtime->context()->cpu()->cpl() !== 0) {
            throw new FaultException(0x0D, 0, 'SYSEXIT CPL check failed');
        }

        $csBaseMsr = $runtime->context()->cpu()->readMsr(0x174); // IA32_SYSENTER_CS
        $csBase = $csBaseMsr->low32() & 0xFFFF;
        $cs = (($csBase + 16) & 0xFFFC) | 0x3; // RPL forced to 3
        $ss = (($csBase + 24) & 0xFFFC) | 0x3; // RPL forced to 3

        $ma = $runtime->memoryAccessor();
        $eip = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $esp = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);

        $this->writeCodeSegment($runtime, $cs & 0xFFFF, 3);
        $ma->write16Bit(RegisterType::SS, $ss & 0xFFFF);
        if ($runtime->context()->cpu()->isProtectedMode() && ($ss & 0xFFFF) !== 0) {
            $descriptor = $this->readSegmentDescriptor($runtime, $ss & 0xFFFF);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $runtime->context()->cpu()->cacheSegmentDescriptor(RegisterType::SS, $descriptor);
            }
        }
        $this->writeRegisterBySize($runtime, RegisterType::ESP, $esp & 0xFFFFFFFF, 32);

        $runtime->context()->cpu()->setCpl(3);
        $runtime->context()->cpu()->setUserMode(true);

        $target = $this->linearCodeAddress($runtime, $cs & 0xFFFF, $eip & 0xFFFFFFFF, 32);
        $runtime->memory()->setOffset($target);

        return ExecutionStatus::SUCCESS;
    }
}
