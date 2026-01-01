<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PushImm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x68, 0x6A]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $hasOperandSizeOverridePrefix = in_array(self::PREFIX_OPERAND_SIZE, $opcodes, true);
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $cpu = $runtime->context()->cpu();

        // In 64-bit mode, PUSH imm32/imm8 pushes 64-bit values (sign-extended), except with 0x66 prefix (pushw).
        // REX.W is ignored for this instruction.
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $pushSize = $hasOperandSizeOverridePrefix ? 16 : 64;
            $value = match ($opcode) {
                0x68 => $pushSize === 16 ? $memory->short() : $memory->signedDword(),
                default => $memory->signedByte(),
            };
            $runtime->memoryAccessor()->push(RegisterType::ESP, $value, $pushSize);
            return ExecutionStatus::SUCCESS;
        }

        $size = $cpu->operandSize();

        $value = $opcode === 0x68
            ? ($size === 32
                ? (($memory->short()) | ($memory->short() << 16)) // basic dword read
                : $memory->short())
            : $memory->signedByte();

        $runtime
            ->memoryAccessor()
            ->push(RegisterType::ESP, $value, $size);

        return ExecutionStatus::SUCCESS;
    }
}
