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
            ->asByte() & 0b11111111;

        $es = $runtime->memoryAccessor()
            ->fetch(($runtime->register())::addressBy(RegisterType::ES))
            ->asByte();

        $di = $runtime->memoryAccessor()
            ->fetch(($runtime->register())::addressBy(RegisterType::EDI))
            ->asByte();

        $runtime
            ->memoryAccessor()
            ->write($es + $di, $byte);

        // TODO: Here is needed to implement decrementing by DF
        $runtime
            ->memoryAccessor()
            ->increment(RegisterType::EDI);

        return ExecutionStatus::SUCCESS;
    }
}
