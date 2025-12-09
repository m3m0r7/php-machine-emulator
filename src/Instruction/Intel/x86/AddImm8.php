<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class AddImm8 implements InstructionInterface
{
    use Instructable;

    /**
     * Implements accumulator-immediate arithmetic/logical ops:
     * ADD/OR/ADC/SBB/AND/SUB/XOR with AL/AX.
     */
    public function opcodes(): array
    {
        return $this->applyPrefixes(array_keys($this->opcodeMap()));
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $map = $this->opcodeMap()[$opcode];
        $isByte = $map['size'] === 8;
        $size = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $mask = $size === 32 ? 0xFFFFFFFF : ($size === 16 ? 0xFFFF : 0xFF);
        $signBit = $size - 1;

        $reader = new EnhanceStreamReader($runtime->memory());
        $operand = $isByte
            ? $reader->streamReader()->byte()
            : ($size === 32 ? $reader->dword() : $reader->short());

        $acc = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        $left = $isByte ? $acc->asLowBit() : $acc->asBytesBySize($size);

        $carryIn = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        $calc = match ($map['op']) {
            'add' => $left + $operand,
            'or' => ($left | $operand),
            'adc' => $left + $operand + $carryIn,
            'sbb' => $left - $operand - $carryIn,
            'and' => ($left & $operand),
            'sub' => $left - $operand,
            'xor' => ($left ^ $operand),
        };

        $carry = match ($map['op']) {
            'add', 'adc' => $calc > $mask,
            'sbb', 'sub' => $calc < 0,
            default => false,
        };

        $result = $calc & $mask;

        // Calculate OF for arithmetic operations
        $signA = ($left >> $signBit) & 1;
        $signB = ($operand >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;

        $overflow = match ($map['op']) {
            'add', 'adc' => ($signA === $signB) && ($signA !== $signR),
            'sub', 'sbb' => ($signA !== $signB) && ($signB === $signR),
            default => false, // Logical ops clear OF
        };

        // Calculate AF (Auxiliary Carry Flag) - carry from bit 3 to bit 4
        $auxCarry = match ($map['op']) {
            'add', 'adc' => (($left & 0x0F) + ($operand & 0x0F) + ($map['op'] === 'adc' ? $carryIn : 0)) > 0x0F,
            'sub', 'sbb' => (($left & 0x0F) - ($operand & 0x0F) - ($map['op'] === 'sbb' ? $carryIn : 0)) < 0,
            default => false, // Logical ops clear AF
        };

        if ($isByte) {
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $result);
        } else {
            $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $result, $size);
        }

        $runtime
            ->memoryAccessor()
            ->updateFlags($result, $size)
            ->setCarryFlag($carry)
            ->setOverflowFlag($overflow)
            ->setAuxiliaryCarryFlag($auxCarry);

        return ExecutionStatus::SUCCESS;
    }

    private function opcodeMap(): array
    {
        return [
            0x04 => ['op' => 'add', 'size' => 8],
            0x05 => ['op' => 'add', 'size' => 16],
            0x0C => ['op' => 'or', 'size' => 8],
            0x0D => ['op' => 'or', 'size' => 16],
            0x14 => ['op' => 'adc', 'size' => 8],
            0x15 => ['op' => 'adc', 'size' => 16],
            0x1C => ['op' => 'sbb', 'size' => 8],
            0x1D => ['op' => 'sbb', 'size' => 16],
            0x24 => ['op' => 'and', 'size' => 8],
            0x25 => ['op' => 'and', 'size' => 16],
            0x2C => ['op' => 'sub', 'size' => 8],
            0x2D => ['op' => 'sub', 'size' => 16],
            0x34 => ['op' => 'xor', 'size' => 8],
            0x35 => ['op' => 'xor', 'size' => 16],
        ];
    }
}
