<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Lodsb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xAC]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $segment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        $linearAddr = $this->segmentOffsetAddress($runtime, $segment, $si);
        $value = $this->readMemory8($runtime, $linearAddr);

        $runtime->memoryAccessor()
            ->writeToLowBit(
                RegisterType::EAX,
                $value,
            );


        $step = $this->stepForElement($runtime, 1);
        $newSi = $si + $step;
        $this->writeIndex($runtime, RegisterType::ESI, $newSi);


        return ExecutionStatus::SUCCESS;
    }
}
