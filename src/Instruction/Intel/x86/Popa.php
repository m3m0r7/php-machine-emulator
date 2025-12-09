<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Popa implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x61]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();
        $size = $runtime->context()->cpu()->operandSize();

        $di = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $si = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $bp = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $ma->pop(RegisterType::ESP, $size); // skip SP
        $bx = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $dx = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $cx = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $ax = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);

        $ma->writeBySize(RegisterType::EDI, $di, $size);
        $ma->writeBySize(RegisterType::ESI, $si, $size);
        $ma->writeBySize(RegisterType::EBP, $bp, $size);
        $ma->writeBySize(RegisterType::EBX, $bx, $size);
        $ma->writeBySize(RegisterType::EDX, $dx, $size);
        $ma->writeBySize(RegisterType::ECX, $cx, $size);
        $ma->writeBySize(RegisterType::EAX, $ax, $size);

        return ExecutionStatus::SUCCESS;
    }
}
