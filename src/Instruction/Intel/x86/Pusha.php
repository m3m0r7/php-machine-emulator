<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Pusha implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x60];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $size = $runtime->runtimeOption()->context()->operandSize();

        $ax = $ma->fetch(RegisterType::EAX)->asBytesBySize($size);
        $cx = $ma->fetch(RegisterType::ECX)->asBytesBySize($size);
        $dx = $ma->fetch(RegisterType::EDX)->asBytesBySize($size);
        $bx = $ma->fetch(RegisterType::EBX)->asBytesBySize($size);
        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($size);
        $bp = $ma->fetch(RegisterType::EBP)->asBytesBySize($size);
        $si = $ma->fetch(RegisterType::ESI)->asBytesBySize($size);
        $di = $ma->fetch(RegisterType::EDI)->asBytesBySize($size);

        foreach ([$ax, $cx, $dx, $bx, $sp, $bp, $si, $di] as $val) {
            $ma->push(RegisterType::ESP, $val, $size);
        }

        return ExecutionStatus::SUCCESS;
    }
}
