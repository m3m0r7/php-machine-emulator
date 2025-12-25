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
 * SYSENTER (0x0F 0x34)
 * Fast system call.
 */
class Sysenter implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0x34]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        if ($runtime->context()->cpu()->cpl() > 0) {
            throw new FaultException(0x0D, 0, 'SYSENTER CPL check failed');
        }

        $cpu = $runtime->context()->cpu();
        $csMsr = $cpu->readMsr(0x174);  // IA32_SYSENTER_CS
        $espMsr = $cpu->readMsr(0x175); // IA32_SYSENTER_ESP
        $eipMsr = $cpu->readMsr(0x176); // IA32_SYSENTER_EIP

        $cs = $csMsr->low32() & 0xFFFC; // RPL forced to 0
        $esp = $espMsr->low32() & 0xFFFFFFFF;
        $eip = $eipMsr->low32() & 0xFFFFFFFF;

        if ($esp === 0 || $eip === 0) {
            throw new FaultException(0x0D, 0, 'SYSENTER MSRs not set');
        }

        $ss = ($cs + 8) & 0xFFFC; // RPL forced to 0
        $ma = $runtime->memoryAccessor();
        $this->writeCodeSegment($runtime, $cs & 0xFFFF, 0);
        $ma->write16Bit(RegisterType::SS, $ss & 0xFFFF);
        if ($runtime->context()->cpu()->isProtectedMode() && ($ss & 0xFFFF) !== 0) {
            $descriptor = $this->readSegmentDescriptor($runtime, $ss & 0xFFFF);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $runtime->context()->cpu()->cacheSegmentDescriptor(RegisterType::SS, $descriptor);
            }
        }
        $this->writeRegisterBySize($runtime, RegisterType::ESP, $esp, 32);

        $runtime->context()->cpu()->setCpl(0);
        $runtime->context()->cpu()->setUserMode(false);

        $target = $this->linearCodeAddress($runtime, $cs & 0xFFFF, $eip, 32);
        $runtime->memory()->setOffset($target);

        return ExecutionStatus::SUCCESS;
    }
}
