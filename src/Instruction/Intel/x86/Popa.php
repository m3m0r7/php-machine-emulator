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
        $ma = $runtime->memoryAccessor();
        $size = $runtime->context()->cpu()->operandSize();

        $espBefore = $ma->fetch(RegisterType::ESP)->asBytesBySize(32);

        // Debug: check if ESP has unexpected upper bits in 32-bit mode
        if ($size === 32 && ($espBefore & 0xFFFF0000) !== 0 && ($espBefore & 0xFFFF0000) !== 0x00010000) {
            $runtime->option()->logger()->debug(sprintf(
                'POPA WARNING: ESP has unexpected upper bits: 0x%08X (size=%d)',
                $espBefore,
                $size
            ));
        }

        $di = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $si = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $bp = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $ma->pop(RegisterType::ESP, $size); // skip SP
        $bx = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $dx = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $cx = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
        $ax = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);

        $espAfter = $ma->fetch(RegisterType::ESP)->asBytesBySize(32);

        // Debug: log POPA with ESP
        $runtime->option()->logger()->debug(sprintf(
            'POPA: ESP before=0x%08X after=0x%08X SI=0x%04X DI=0x%04X AX=0x%04X BX=0x%04X',
            $espBefore, $espAfter, $si, $di, $ax, $bx
        ));

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
