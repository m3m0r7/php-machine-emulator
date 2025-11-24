<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Stosw implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xAB];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $value = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();

        $di = $runtime->memoryAccessor()->fetch(($runtime->register())::addressBy(RegisterType::EDI))->asByte();

        $address = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        $runtime->memoryAccessor()->allocate($address, safe: false);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($address, $value);

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->add(RegisterType::EDI, $runtime->memoryAccessor()->shouldDirectionFlag() ? -2 : 2);

        return ExecutionStatus::SUCCESS;
    }
}
