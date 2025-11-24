<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Scasb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xAE];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $di = $runtime->memoryAccessor()->fetch(RegisterType::EDI)->asByte();

        $value = $this->readMemory8(
            $runtime,
            $this->segmentOffsetAddress($runtime, RegisterType::ES, $di),
        );
        $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();

        $runtime->memoryAccessor()->updateFlags($al - $value, 8)->setCarryFlag($al < $value);

        $step = $runtime->memoryAccessor()->shouldDirectionFlag() ? -1 : 1;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->add(RegisterType::EDI, $step);

        return ExecutionStatus::SUCCESS;
    }
}
