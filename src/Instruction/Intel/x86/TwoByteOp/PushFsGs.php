<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

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
        return $this->applyPrefixes([
            [0x0F, 0xA0], // PUSH FS
            [0x0F, 0xA8], // PUSH GS
        ], [PrefixClass::Operand]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
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
