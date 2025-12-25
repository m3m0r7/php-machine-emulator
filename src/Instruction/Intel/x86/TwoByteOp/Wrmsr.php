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
use PHPMachineEmulator\Util\UInt64;

/**
 * WRMSR (0x0F 0x30)
 * Write Model-Specific Register.
 */
class Wrmsr implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0x30]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        if ($runtime->context()->cpu()->cpl() !== 0) {
            throw new FaultException(0x0D, 0, 'WRMSR privilege check failed');
        }

        $ma = $runtime->memoryAccessor();
        $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $eax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);
        $edx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);
        $value = UInt64::fromParts($eax, $edx);

        $runtime->context()->cpu()->writeMsr($ecx, $value);

        if ($ecx === 0x1B) { // APIC_BASE
            $enable = !$value->and(1 << 11)->isZero();
            $runtime->context()->cpu()->apicState()->setApicBase($value->and(0xFFFFF000)->low32(), $enable);
        } elseif ($ecx === 0xC0000080) { // EFER
            // EFER.LMA (bit 10) is read-only; it is set/cleared by the CPU when IA-32e becomes active/inactive.
            $ma->writeEfer($value->toInt() & ~(1 << 10));
            $this->updateIa32eMode($runtime);
        }

        return ExecutionStatus::SUCCESS;
    }
}
