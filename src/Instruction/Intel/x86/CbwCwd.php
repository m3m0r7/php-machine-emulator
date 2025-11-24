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
        if ($opcode === 0x98) {
            // CBW: sign-extend AL to AX
            $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $ah = ($al & 0x80) ? 0xFF : 0x00;
            $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, $ah);
            return ExecutionStatus::SUCCESS;
        }

        // CWD: sign-extend AX into DX:AX
        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();
        $dx = ($ax & 0x8000) ? 0xFFFF : 0x0000;
        $runtime->memoryAccessor()->write16Bit(RegisterType::EDX, $dx);

        return ExecutionStatus::SUCCESS;
    }
}
