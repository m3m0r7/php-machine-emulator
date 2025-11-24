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

        $di = $runtime->memoryAccessor()
            ->fetch(($runtime->register())::addressBy(RegisterType::EDI))
            ->asByte();

        $address = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        $runtime
            ->memoryAccessor()
            ->allocate($address, safe: false);

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->writeBySize($address, $byte, 8);

        // TODO: Here is needed to implement decrementing by DF
        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->add(RegisterType::EDI, $runtime->memoryAccessor()->shouldDirectionFlag() ? -1 : 1);

        return ExecutionStatus::SUCCESS;
    }
}
