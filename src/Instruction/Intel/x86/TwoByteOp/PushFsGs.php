<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * PUSH FS/GS (0x0F 0xA0 / 0x0F 0xA8)
 */
class PushFsGs implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            [0x0F, 0xA0], // PUSH FS
            [0x0F, 0xA8], // PUSH GS
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $secondByte = $opcode & 0xFF;
        if ($opcode > 0xFF) {
            $secondByte = $opcode & 0xFF;
        }

        $seg = $secondByte === 0xA0 ? RegisterType::FS : RegisterType::GS;
        $opSize = $runtime->context()->cpu()->operandSize();
        $val = $runtime->memoryAccessor()->fetch($seg)->asByte() & 0xFFFF;
        $runtime->memoryAccessor()->push(RegisterType::ESP, $val, $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
