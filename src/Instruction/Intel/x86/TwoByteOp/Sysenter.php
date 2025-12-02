<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

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
        return [[0x0F, 0x34]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        if ($runtime->context()->cpu()->cpl() > 0) {
            throw new FaultException(0x0D, 0, 'SYSENTER CPL check failed');
        }

        $cs = Rdmsr::readMsr(0x174);
        $esp = Rdmsr::readMsr(0x175);
        $eip = Rdmsr::readMsr(0x176);

        if ($esp === 0 || $eip === 0) {
            throw new FaultException(0x0D, 0, 'SYSENTER MSRs not set');
        }

        $ss = ($cs + 8) & 0xFFFF;
        $ma = $runtime->memoryAccessor();
        $ma->write16Bit(RegisterType::CS, $cs & 0xFFFF);
        $ma->write16Bit(RegisterType::SS, $ss);
        $this->writeRegisterBySize($runtime, RegisterType::ESP, $esp & 0xFFFFFFFF, 32);

        $runtime->context()->cpu()->setCpl(0);
        $runtime->context()->cpu()->setUserMode(false);

        $target = $this->linearCodeAddress($runtime, $cs, $eip & 0xFFFFFFFF, 32);
        $runtime->memory()->setOffset($target);

        return ExecutionStatus::SUCCESS;
    }
}
