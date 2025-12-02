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
 * SYSEXIT (0x0F 0x35)
 * Fast return from system call.
 */
class Sysexit implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [[0x0F, 0x35]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        if ($runtime->context()->cpu()->cpl() !== 0) {
            throw new FaultException(0x0D, 0, 'SYSEXIT CPL check failed');
        }

        $csBase = Rdmsr::readMsr(0x174);
        $cs = ($csBase + 16) & 0xFFFF;
        $ss = ($csBase + 24) & 0xFFFF;

        $ma = $runtime->memoryAccessor();
        $eip = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $esp = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);

        $ma->write16Bit(RegisterType::CS, $cs);
        $ma->write16Bit(RegisterType::SS, $ss);
        $this->writeRegisterBySize($runtime, RegisterType::ESP, $esp & 0xFFFFFFFF, 32);

        $runtime->context()->cpu()->setCpl(3);
        $runtime->context()->cpu()->setUserMode(true);

        $target = $this->linearCodeAddress($runtime, $cs, $eip & 0xFFFFFFFF, 32);
        $runtime->memory()->setOffset($target);

        return ExecutionStatus::SUCCESS;
    }
}
