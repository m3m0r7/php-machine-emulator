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
 * POP FS/GS (0x0F 0xA1 / 0x0F 0xA9)
 */
class PopFsGs implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([
            [0x0F, 0xA1], // POP FS
            [0x0F, 0xA9], // POP GS
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

        $seg = $secondByte === 0xA1 ? RegisterType::FS : RegisterType::GS;
        $opSize = $runtime->context()->cpu()->operandSize();
        $val = $runtime->memoryAccessor()->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize) & 0xFFFF;
        $runtime->memoryAccessor()->write16Bit($seg, $val);

        return ExecutionStatus::SUCCESS;
    }
}
