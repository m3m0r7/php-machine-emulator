<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Stosb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xAA];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $byte = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EAX)
            ->asLowBit();

        $di = $this->readIndex($runtime, RegisterType::EDI);

        $address = $this->translateLinear($runtime, $this->segmentOffsetAddress($runtime, RegisterType::ES, $di), true);

        $runtime
            ->memoryAccessor()
            ->allocate($address, safe: false);

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->writeBySize($address, $byte, 8);

        $step = $this->stepForElement($runtime, 1);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $step);

        return ExecutionStatus::SUCCESS;
    }
}
