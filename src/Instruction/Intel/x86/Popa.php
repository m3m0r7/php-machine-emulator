<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Popa implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x61];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $size = $runtime->runtimeOption()->context()->operandSize();

        $di = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $si = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $bp = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $ma->pop(RegisterType::ESP, $size); // skip SP
        $bx = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $dx = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $cx = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $ax = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);

        $ma->write16Bit(RegisterType::EDI, $di);
        $ma->write16Bit(RegisterType::ESI, $si);
        $ma->write16Bit(RegisterType::EBP, $bp);
        $ma->write16Bit(RegisterType::EBX, $bx);
        $ma->write16Bit(RegisterType::EDX, $dx);
        $ma->write16Bit(RegisterType::ECX, $cx);
        $ma->write16Bit(RegisterType::EAX, $ax);

        return ExecutionStatus::SUCCESS;
    }
}
