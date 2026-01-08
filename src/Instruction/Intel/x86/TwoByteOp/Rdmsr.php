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
use PHPMachineEmulator\Util\Tsc;

/**
 * RDMSR (0x0F 0x32)
 * Read Model-Specific Register.
 */
class Rdmsr implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0x32]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        if ($runtime->context()->cpu()->cpl() !== 0) {
            throw new FaultException(0x0D, 0, 'RDMSR privilege check failed');
        }

        $ma = $runtime->memoryAccessor();
        $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $cpu = $runtime->context()->cpu();
        $value = $cpu->readMsr($ecx);

        if ($ecx === 0x10) { // TSC MSR
            $value = UInt64::of(Tsc::read());
        } elseif ($ecx === 0x1B) { // APIC_BASE
            $value = UInt64::of($runtime->context()->cpu()->apicState()->readMsrApicBase());
        } elseif ($ecx === 0xC0000080) { // EFER
            $value = UInt64::of($ma->readEfer());
        } elseif (in_array($ecx, [0x174, 0x175, 0x176], true)) { // SYSENTER_CS/ESP/EIP
            $value = $cpu->readMsr($ecx);
        }

        $this->writeRegisterBySize($runtime, RegisterType::EAX, $value->low32(), 32);
        $this->writeRegisterBySize($runtime, RegisterType::EDX, $value->high32(), 32);

        return ExecutionStatus::SUCCESS;
    }
}
