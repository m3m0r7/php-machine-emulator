<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group2 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xC0, 0xC1, 0xD0, 0xD1, 0xD2, 0xD3];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader
            ->byteAsModRegRM();
        $opSize = $this->isByteOp($opcode) ? 8 : $runtime->runtimeOption()->context()->operandSize();

        return match ($modRegRM->digit()) {
            0x1 => $this->rotateRight($runtime, $opcode, $enhancedStreamReader, $modRegRM, $opSize),
            0x4 => $this->shiftLeft($runtime, $opcode, $enhancedStreamReader, $modRegRM, $opSize),
            0x5 => $this->shiftRightLogical($runtime, $opcode, $enhancedStreamReader, $modRegRM, $opSize),
            0x7 => $this->shiftRightArithmetic($runtime, $opcode, $enhancedStreamReader, $modRegRM, $opSize),
            default => throw new ExecutionException(
                sprintf('The digit (0b%s) is not supported yet', decbin($modRegRM->digit()))
            ),
        };
    }

    protected function count(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): int
    {
        return match ($opcode) {
            0xC0, 0xC1 => $runtime->streamReader()->byte(),
            0xD0, 0xD1 => 1,
            0xD2, 0xD3 => $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asLowBit(),
            default => 0,
        };
    }

    protected function isByteOp(int $opcode): bool
    {
        return in_array($opcode, [0xC0, 0xD0, 0xD2], true);
    }

    protected function rotateRight(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;

        if ($this->isByteOp($opcode)) {
            $value = $this->readRm8($runtime, $reader, $modRegRM) & 0xFF;
            $count = $operand % 8;
            $result = $count === 0 ? $value : (($value >> $count) | (($value & ((1 << $count) - 1)) << (8 - $count)));

            $this->writeRm8($runtime, $reader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag($count > 0 ? (($value >> ($count - 1)) & 0x1) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $value = $this->readRm($runtime, $reader, $modRegRM, $size) & $mask;
        $mod = $size;
        $count = $operand % $mod;
        $result = $count === 0 ? $value : (($value >> $count) | (($value & ((1 << $count) - 1)) << ($mod - $count)));
        $this->writeRm($runtime, $reader, $modRegRM, $result, $size);
        $runtime->memoryAccessor()->setCarryFlag($count > 0 ? (($value >> ($count - 1)) & 0x1) !== 0 : false);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }

    protected function shiftLeft(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;

        if ($this->isByteOp($opcode)) {
            $value = $this->readRm8($runtime, $reader, $modRegRM);
            $result = ($value << $operand) & 0xFF;

            $this->writeRm8($runtime, $reader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value << ($operand - 1)) & 0x100) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $value = $this->readRm($runtime, $reader, $modRegRM, $size);
        $result = ($value << $operand) & $mask;
        $this->writeRm($runtime, $reader, $modRegRM, $result, $size);
        $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value << ($operand - 1)) & ($mask + 1)) !== 0 : false);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }

    protected function shiftRightLogical(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;

        if ($this->isByteOp($opcode)) {
            $value = $this->readRm8($runtime, $reader, $modRegRM);
            $result = ($value >> $operand) & 0xFF;

            $this->writeRm8($runtime, $reader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value >> ($operand - 1)) & 0x1) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $value = $this->readRm($runtime, $reader, $modRegRM, $size);
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($value >> $operand) & $mask;

        $this->writeRm($runtime, $reader, $modRegRM, $result, $size);
        $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value >> ($operand - 1)) & 0x1) !== 0 : false);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }

    protected function shiftRightArithmetic(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;

        if ($this->isByteOp($opcode)) {
            $value = $this->readRm8($runtime, $reader, $modRegRM);
            $sign = $value & 0x80;
            $result = ($value >> $operand) & 0x7F;
            if ($sign) {
                $result |= 0x80;
            }

            $this->writeRm8($runtime, $reader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value >> ($operand - 1)) & 0x1) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $value = $this->readRm($runtime, $reader, $modRegRM, $size);
        $signBit = 1 << ($size - 1);
        $sign = $value & $signBit;
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($value >> $operand) & (~$signBit & $mask);
        if ($sign) {
            $result |= $signBit;
        }

        $this->writeRm($runtime, $reader, $modRegRM, $result, $size);
        $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value >> ($operand - 1)) & 0x1) !== 0 : false);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }
}
