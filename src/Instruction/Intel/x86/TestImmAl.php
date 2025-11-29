<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * TEST AL, imm8 (0xA8) - Test AL with immediate byte
 * TEST AX/EAX, imm16/32 (0xA9) - Test AX/EAX with immediate word/dword
 */
class TestImmAl implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xA8, 0xA9];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        if ($opcode === 0xA8) {
            // TEST AL, imm8
            $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $imm = $runtime->memory()->byte();
            $result = $al & $imm;
            $runtime->memoryAccessor()->setCarryFlag(false)->setOverflowFlag(false)->updateFlags($result, 8);
        } else {
            // TEST AX/EAX, imm16/32
            $opSize = $runtime->context()->cpu()->operandSize();
            if ($opSize === 32) {
                $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asDword();
                $imm = $runtime->memory()->dword();
                $result = ($ax & $imm) & 0xFFFFFFFF;
            } else {
                $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(16);
                $imm = $runtime->memory()->short();
                $result = ($ax & $imm) & 0xFFFF;
            }
            $runtime->memoryAccessor()->setCarryFlag(false)->setOverflowFlag(false)->updateFlags($result, $opSize);
        }

        return ExecutionStatus::SUCCESS;
    }
}
