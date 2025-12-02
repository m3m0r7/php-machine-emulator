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
 * WRMSR (0x0F 0x30)
 * Write Model-Specific Register.
 */
class Wrmsr implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [[0x0F, 0x30]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        if ($runtime->context()->cpu()->cpl() !== 0) {
            throw new FaultException(0x0D, 0, 'WRMSR privilege check failed');
        }

        $ma = $runtime->memoryAccessor();
        $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $eax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);
        $edx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);
        $value = ($edx << 32) | ($eax & 0xFFFFFFFF);

        Rdmsr::writeMsr($ecx, $value & 0xFFFFFFFFFFFFFFFF);

        if ($ecx === 0x1B) { // APIC_BASE
            $enable = ($value & (1 << 11)) !== 0;
            $runtime->context()->cpu()->apicState()->setApicBase($value & 0xFFFFF000, $enable);
        } elseif ($ecx === 0xC0000080) { // EFER
            $ma->writeEfer($value);
        }

        return ExecutionStatus::SUCCESS;
    }
}
