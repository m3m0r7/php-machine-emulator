<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Pusha implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x60]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();
        $size = $runtime->context()->cpu()->operandSize();

        $espBefore = $ma->fetch(RegisterType::ESP)->asBytesBySize(32);

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
