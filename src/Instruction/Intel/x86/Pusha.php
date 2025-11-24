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

        $ax = $ma->fetch(RegisterType::EAX)->asByte();
        $cx = $ma->fetch(RegisterType::ECX)->asByte();
        $dx = $ma->fetch(RegisterType::EDX)->asByte();
        $bx = $ma->fetch(RegisterType::EBX)->asByte();
        $sp = $ma->fetch(RegisterType::ESP)->asByte();
        $bp = $ma->fetch(RegisterType::EBP)->asByte();
        $si = $ma->fetch(RegisterType::ESI)->asByte();
        $di = $ma->fetch(RegisterType::EDI)->asByte();

        foreach ([$ax, $cx, $dx, $bx, $sp, $bp, $si, $di] as $val) {
            $ma->push(RegisterType::ESP, $val, $size);
        }

        return ExecutionStatus::SUCCESS;
    }
}
