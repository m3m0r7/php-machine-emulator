<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * CMPXCHG8B (0x0F 0xC7 /1)
 * Compare and exchange 8 bytes.
 */
class Cmpxchg8b implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0xC7]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();

        if (ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('CMPXCHG8B requires memory operand');
        }

        if (($modrm->registerOrOPCode() & 0x7) !== 0x1) {
            return ExecutionStatus::SUCCESS;
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $low = $this->readMemory32($runtime, $address);
        $high = $this->readMemory32($runtime, $address + 4);

        $ma = $runtime->memoryAccessor();
        $eax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);
        $edx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);

        if ($low === $eax && $high === $edx) {
            $ma->setZeroFlag(true);
            $ebx = $ma->fetch(RegisterType::EBX)->asBytesBySize(32);
            $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
            $this->writeMemory32($runtime, $address, $ebx);
            $this->writeMemory32($runtime, $address + 4, $ecx);
        } else {
            $ma->setZeroFlag(false);
            $this->writeRegisterBySize($runtime, RegisterType::EAX, $low, 32);
            $this->writeRegisterBySize($runtime, RegisterType::EDX, $high, 32);
        }

        return ExecutionStatus::SUCCESS;
    }
}
