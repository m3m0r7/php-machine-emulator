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
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $enhancedStreamReader
            ->byteAsModRegRM();
        $opSize = $this->isByteOp($opcode) ? 8 : $runtime->context()->cpu()->operandSize();

        // Debug: trace Group2 operations in problem region
        $ip = $runtime->memory()->offset();

        return match ($modRegRM->digit()) {
            0x0 => $this->rotateLeft($runtime, $opcode, $enhancedStreamReader, $modRegRM, $opSize),
            0x1 => $this->rotateRight($runtime, $opcode, $enhancedStreamReader, $modRegRM, $opSize),
            0x2 => $this->rotateCarryLeft($runtime, $opcode, $enhancedStreamReader, $modRegRM, $opSize),
            0x3 => $this->rotateCarryRight($runtime, $opcode, $enhancedStreamReader, $modRegRM, $opSize),
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
            0xC0, 0xC1 => $runtime->memory()->byte(),
            0xD0, 0xD1 => 1,
            0xD2, 0xD3 => $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asLowBit(),
            default => 0,
        };
    }

    protected function isByteOp(int $opcode): bool
    {
        return in_array($opcode, [0xC0, 0xD0, 0xD2], true);
    }

    protected function rotateLeft(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;

        if ($this->isByteOp($opcode)) {
            $value = $isRegister
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress()) & 0xFF
                : $this->readMemory8($runtime, $address) & 0xFF;
            $count = $operand % 8;
            $result = $count === 0 ? $value : ((($value << $count) | ($value >> (8 - $count))) & 0xFF);

            if ($isRegister) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $address, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($count > 0 ? ($result & 0x1) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $value = $isRegister
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size) & $mask
            : ($size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address)) & $mask;
        $mod = $size;
        $count = $operand % $mod;
        $result = $count === 0 ? $value : ((($value << $count) | ($value >> ($mod - $count))) & $mask);
        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
        } else {
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }
        $runtime->memoryAccessor()->setCarryFlag($count > 0 ? ($result & 0x1) !== 0 : false);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }

    protected function rotateRight(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;

        if ($this->isByteOp($opcode)) {
            $value = $isRegister
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress()) & 0xFF
                : $this->readMemory8($runtime, $address) & 0xFF;
            $count = $operand % 8;
            $result = $count === 0 ? $value : (($value >> $count) | (($value & ((1 << $count) - 1)) << (8 - $count)));

            if ($isRegister) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $address, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($count > 0 ? (($value >> ($count - 1)) & 0x1) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $value = $isRegister
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size) & $mask
            : ($size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address)) & $mask;
        $mod = $size;
        $count = $operand % $mod;
        $result = $count === 0 ? $value : (($value >> $count) | (($value & ((1 << $count) - 1)) << ($mod - $count)));
        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
        } else {
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }
        $runtime->memoryAccessor()->setCarryFlag($count > 0 ? (($value >> ($count - 1)) & 0x1) !== 0 : false);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }

    protected function rotateCarryLeft(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;
        $carry = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        if ($this->isByteOp($opcode)) {
            $value = $isRegister
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress()) & 0xFF
                : $this->readMemory8($runtime, $address) & 0xFF;
            $count = $operand % 9; // 8 bits + 1 carry bit
            $result = $value;
            $cf = $carry;
            for ($i = 0; $i < $count; $i++) {
                $newCf = ($result >> 7) & 1;
                $result = (($result << 1) | $cf) & 0xFF;
                $cf = $newCf;
            }

            if ($isRegister) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $address, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $value = $isRegister
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size) & $mask
            : ($size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address)) & $mask;
        $mod = $size + 1; // size bits + 1 carry bit
        $count = $operand % $mod;
        $result = $value;
        $cf = $carry;
        for ($i = 0; $i < $count; $i++) {
            $newCf = ($result >> ($size - 1)) & 1;
            $result = (($result << 1) | $cf) & $mask;
            $cf = $newCf;
        }

        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
        } else {
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }
        $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }

    protected function rotateCarryRight(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;
        $carry = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        if ($this->isByteOp($opcode)) {
            $value = $isRegister
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress()) & 0xFF
                : $this->readMemory8($runtime, $address) & 0xFF;
            $count = $operand % 9; // 8 bits + 1 carry bit
            $result = $value;
            $cf = $carry;
            for ($i = 0; $i < $count; $i++) {
                $newCf = $result & 1;
                $result = (($result >> 1) | ($cf << 7)) & 0xFF;
                $cf = $newCf;
            }

            if ($isRegister) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $address, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $value = $isRegister
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size) & $mask
            : ($size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address)) & $mask;
        $mod = $size + 1; // size bits + 1 carry bit
        $count = $operand % $mod;
        $result = $value;
        $cf = $carry;
        for ($i = 0; $i < $count; $i++) {
            $newCf = $result & 1;
            $result = (($result >> 1) | ($cf << ($size - 1))) & $mask;
            $cf = $newCf;
        }

        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
        } else {
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }
        $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }

    protected function shiftLeft(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;

        if ($this->isByteOp($opcode)) {
            $value = $isRegister
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $address);
            $result = ($value << $operand) & 0xFF;

            if ($isRegister) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $address, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value << ($operand - 1)) & 0x100) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $value = $isRegister
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size)
            : ($size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address));
        $result = ($value << $operand) & $mask;
        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
        } else {
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }
        $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value << ($operand - 1)) & ($mask + 1)) !== 0 : false);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }

    protected function shiftRightLogical(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;

        if ($this->isByteOp($opcode)) {
            $value = $isRegister
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $address);
            $result = ($value >> $operand) & 0xFF;

            if ($isRegister) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $address, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value >> ($operand - 1)) & 0x1) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $value = $isRegister
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size)
            : ($size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address));
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($value >> $operand) & $mask;

        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
        } else {
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }
        $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value >> ($operand - 1)) & 0x1) !== 0 : false);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }

    protected function shiftRightArithmetic(RuntimeInterface $runtime, int $opcode, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $operand = $this->count($runtime, $opcode, $reader, $modRegRM) & 0x1F;

        if ($this->isByteOp($opcode)) {
            $value = $isRegister
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $address);
            $sign = $value & 0x80;
            $result = ($value >> $operand) & 0x7F;
            if ($sign) {
                $result |= 0x80;
            }

            if ($isRegister) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $address, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value >> ($operand - 1)) & 0x1) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);

            return ExecutionStatus::SUCCESS;
        }

        $value = $isRegister
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size)
            : ($size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address));
        $signBit = 1 << ($size - 1);
        $sign = $value & $signBit;
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($value >> $operand) & (~$signBit & $mask);
        if ($sign) {
            $result |= $signBit;
        }

        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
        } else {
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }
        $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value >> ($operand - 1)) & 0x1) !== 0 : false);
        $runtime->memoryAccessor()->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }
}
