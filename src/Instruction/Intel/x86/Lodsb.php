<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Lodsb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xAC];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $segment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        $linearAddr = $this->segmentOffsetAddress($runtime, $segment, $si);
        $value = $this->readMemory8($runtime, $linearAddr);

        // Debug: log lodsb operations
        $segValue = $runtime->memoryAccessor()->fetch($segment)->asByte();
        $runtime->option()->logger()->debug(sprintf(
            'LODSB: %s:SI=0x%04X:0x%04X linear=0x%05X value=0x%02X (char=%s)',
            $segment->name,
            $segValue,
            $si,
            $linearAddr,
            $value,
            $value >= 0x20 && $value < 0x7F ? chr($value) : '.'
        ));

        $runtime->memoryAccessor()
            ->enableUpdateFlags(false)
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
