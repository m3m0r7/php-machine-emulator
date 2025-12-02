<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class CbwCwd implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x98, 0x99];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $ma = $runtime->memoryAccessor();

        if ($opcode === 0x98) {
            if ($opSize === 16) {
                // CBW: sign-extend AL to AX
                $al = $ma->fetch(RegisterType::EAX)->asLowBit();
                $ah = ($al & 0x80) ? 0xFF : 0x00;
                $ma->writeToHighBit(RegisterType::EAX, $ah);
            } else {
                // CWDE: sign-extend AX to EAX
                $ax = $ma->fetch(RegisterType::EAX)->asByte();
                $signBit = ($ax & 0x8000) !== 0;
                $eax = $signBit ? (0xFFFF0000 | $ax) : $ax;
                $ma->enableUpdateFlags(false)->writeBySize(RegisterType::EAX, $eax, 32);
            }
            return ExecutionStatus::SUCCESS;
        }

        // 0x99
        if ($opSize === 16) {
            // CWD: sign-extend AX into DX:AX
            $ax = $ma->fetch(RegisterType::EAX)->asByte();
            $dx = ($ax & 0x8000) ? 0xFFFF : 0x0000;
            $ma->write16Bit(RegisterType::EDX, $dx);
        } else {
            // CDQ: sign-extend EAX into EDX:EAX
            $eax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);
            $edx = ($eax & 0x80000000) ? 0xFFFFFFFF : 0x00000000;
            $ma->enableUpdateFlags(false)->writeBySize(RegisterType::EDX, $edx, 32);
        }

        return ExecutionStatus::SUCCESS;
    }
}
