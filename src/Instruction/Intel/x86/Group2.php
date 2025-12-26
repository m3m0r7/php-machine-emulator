<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class Group2 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xC0, 0xC1, 0xD0, 0xD1, 0xD2, 0xD3]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory
            ->byteAsModRegRM();
        $opSize = $this->isByteOp($opcode) ? 8 : $runtime->context()->cpu()->operandSize();

        return match ($modRegRM->digit()) {
            0x0 => $this->rotateLeft($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x1 => $this->rotateRight($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x2 => $this->rotateCarryLeft($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x3 => $this->rotateCarryRight($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x4, 0x6 => $this->shiftLeft($runtime, $opcode, $memory, $modRegRM, $opSize), // 0x6 is undocumented SAL alias
            0x5 => $this->shiftRightLogical($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x7 => $this->shiftRightArithmetic($runtime, $opcode, $memory, $modRegRM, $opSize),
            default => throw new ExecutionException(
                sprintf('The digit (0b%s) is not supported yet', decbin($modRegRM->digit()))
            ),
        };
    }

    protected function count(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): int
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

    private function countMask(int $size): int
    {
        // In 64-bit mode with 64-bit operand, shift/rotate counts are masked to 6 bits (0-63).
        return $size === 64 ? 0x3F : 0x1F;
    }

    protected function rotateLeft(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $operand = $this->count($runtime, $opcode, $memory, $modRegRM) & $this->countMask($size);

        // x86 semantics: count==0 leaves operand and flags unchanged (and does not write memory).
        if ($operand === 0) {
            return ExecutionStatus::SUCCESS;
        }

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
            // ROL only affects CF and OF, not SF/ZF/PF/AF
            if ($count > 0) {
                $cf = ($result & 0x1) !== 0;
                $runtime->memoryAccessor()->setCarryFlag($cf);
                // OF is only defined for count=1: OF = MSB XOR CF
                if ($operand === 1) {
                    $msb = ($result >> 7) & 1;
                    $runtime->memoryAccessor()->setOverflowFlag($msb !== ($cf ? 1 : 0));
                }
            }

            return ExecutionStatus::SUCCESS;
        }

        if ($size === 64) {
            $valueU = $isRegister
                ? UInt64::of($this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64))
                : $this->readMemory64($runtime, $address);

            $count = $operand % 64;
            $resultU = $count === 0
                ? $valueU
                : $valueU->shl($count)->or($valueU->shr(64 - $count));

            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $address, $resultU);
            }

            // ROL only affects CF and OF, not SF/ZF/PF/AF
            if ($count > 0) {
                $cf = ($resultU->low32() & 0x1) !== 0;
                $runtime->memoryAccessor()->setCarryFlag($cf);
                if ($operand === 1) {
                    $runtime->memoryAccessor()->setOverflowFlag($resultU->isNegativeSigned() !== $cf);
                }
            }

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
        // ROL only affects CF and OF, not SF/ZF/PF/AF
        if ($count > 0) {
            $cf = ($result & 0x1) !== 0;
            $runtime->memoryAccessor()->setCarryFlag($cf);
            // OF is only defined for count=1: OF = MSB XOR CF
            if ($operand === 1) {
                $msb = ($result >> ($size - 1)) & 1;
                $runtime->memoryAccessor()->setOverflowFlag($msb !== ($cf ? 1 : 0));
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function rotateRight(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $operand = $this->count($runtime, $opcode, $memory, $modRegRM) & $this->countMask($size);

        // x86 semantics: count==0 leaves operand and flags unchanged (and does not write memory).
        if ($operand === 0) {
            return ExecutionStatus::SUCCESS;
        }

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
            // ROR only affects CF and OF, not SF/ZF/PF/AF
            if ($count > 0) {
                $cf = (($value >> ($count - 1)) & 0x1) !== 0;
                $runtime->memoryAccessor()->setCarryFlag($cf);
                // OF is only defined for count=1: OF = MSB XOR (MSB-1) of result
                if ($operand === 1) {
                    $msb = ($result >> 7) & 1;
                    $msb1 = ($result >> 6) & 1;
                    $runtime->memoryAccessor()->setOverflowFlag($msb !== $msb1);
                }
            }

            return ExecutionStatus::SUCCESS;
        }

        if ($size === 64) {
            $valueU = $isRegister
                ? UInt64::of($this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64))
                : $this->readMemory64($runtime, $address);

            $count = $operand % 64;
            $resultU = $count === 0
                ? $valueU
                : $valueU->shr($count)->or($valueU->shl(64 - $count));

            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $address, $resultU);
            }

            // ROR only affects CF and OF, not SF/ZF/PF/AF
            if ($count > 0) {
                $cf = $resultU->isNegativeSigned();
                $runtime->memoryAccessor()->setCarryFlag($cf);
                if ($operand === 1) {
                    $msb1 = ($resultU->shr(62)->low32() & 0x1) !== 0;
                    $runtime->memoryAccessor()->setOverflowFlag($cf !== $msb1);
                }
            }

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
        // ROR only affects CF and OF, not SF/ZF/PF/AF
        if ($count > 0) {
            $cf = (($value >> ($count - 1)) & 0x1) !== 0;
            $runtime->memoryAccessor()->setCarryFlag($cf);
            // OF is only defined for count=1: OF = MSB XOR (MSB-1) of result
            if ($operand === 1) {
                $msb = ($result >> ($size - 1)) & 1;
                $msb1 = ($result >> ($size - 2)) & 1;
                $runtime->memoryAccessor()->setOverflowFlag($msb !== $msb1);
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function rotateCarryLeft(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $operand = $this->count($runtime, $opcode, $memory, $modRegRM) & $this->countMask($size);

        // x86 semantics: count==0 leaves operand and flags unchanged (and does not write memory).
        if ($operand === 0) {
            return ExecutionStatus::SUCCESS;
        }

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
            // RCL only affects CF and OF, not SF/ZF/PF/AF
            $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
            // OF is only defined for count=1: OF = MSB XOR CF
            if ($operand === 1) {
                $msb = ($result >> 7) & 1;
                $runtime->memoryAccessor()->setOverflowFlag($msb !== $cf);
            }

            return ExecutionStatus::SUCCESS;
        }

        if ($size === 64) {
            $valueU = $isRegister
                ? UInt64::of($this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64))
                : $this->readMemory64($runtime, $address);

            $count = $operand % 65; // 64 bits + carry
            $resultU = $valueU;
            $cf = $carry;
            for ($i = 0; $i < $count; $i++) {
                $newCf = ($resultU->shr(63)->low32() & 0x1);
                $resultU = $resultU->shl(1);
                if ($cf !== 0) {
                    $resultU = $resultU->or(1);
                }
                $cf = $newCf;
            }

            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $address, $resultU);
            }

            // RCL only affects CF and OF, not SF/ZF/PF/AF
            $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
            // OF is only defined for count=1: OF = MSB XOR CF
            if ($operand === 1) {
                $runtime->memoryAccessor()->setOverflowFlag($resultU->isNegativeSigned() !== ($cf !== 0));
            }

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
        // RCL only affects CF and OF, not SF/ZF/PF/AF
        $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
        // OF is only defined for count=1: OF = MSB XOR CF
        if ($operand === 1) {
            $msb = ($result >> ($size - 1)) & 1;
            $runtime->memoryAccessor()->setOverflowFlag($msb !== $cf);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function rotateCarryRight(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $operand = $this->count($runtime, $opcode, $memory, $modRegRM) & $this->countMask($size);

        // x86 semantics: count==0 leaves operand and flags unchanged (and does not write memory).
        if ($operand === 0) {
            return ExecutionStatus::SUCCESS;
        }

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
            // RCR only affects CF and OF, not SF/ZF/PF/AF
            $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
            // OF is only defined for count=1: OF = MSB XOR (MSB-1) of result
            if ($operand === 1) {
                $msb = ($result >> 7) & 1;
                $msb1 = ($result >> 6) & 1;
                $runtime->memoryAccessor()->setOverflowFlag($msb !== $msb1);
            }

            return ExecutionStatus::SUCCESS;
        }

        if ($size === 64) {
            $valueU = $isRegister
                ? UInt64::of($this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64))
                : $this->readMemory64($runtime, $address);

            $count = $operand % 65; // 64 bits + carry
            $resultU = $valueU;
            $cf = $carry;
            $msbMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
            for ($i = 0; $i < $count; $i++) {
                $newCf = $resultU->low32() & 0x1;
                $resultU = $resultU->shr(1);
                if ($cf !== 0) {
                    $resultU = $resultU->or($msbMask);
                }
                $cf = $newCf;
            }

            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $address, $resultU);
            }

            // RCR only affects CF and OF, not SF/ZF/PF/AF
            $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
            // OF is only defined for count=1: OF = MSB XOR (MSB-1) of result
            if ($operand === 1) {
                $msb = $resultU->isNegativeSigned();
                $msb1 = ($resultU->shr(62)->low32() & 0x1) !== 0;
                $runtime->memoryAccessor()->setOverflowFlag($msb !== $msb1);
            }

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
        // RCR only affects CF and OF, not SF/ZF/PF/AF
        $runtime->memoryAccessor()->setCarryFlag($cf !== 0);
        // OF is only defined for count=1: OF = MSB XOR (MSB-1) of result
        if ($operand === 1) {
            $msb = ($result >> ($size - 1)) & 1;
            $msb1 = ($result >> ($size - 2)) & 1;
            $runtime->memoryAccessor()->setOverflowFlag($msb !== $msb1);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function shiftLeft(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $operand = $this->count($runtime, $opcode, $memory, $modRegRM) & $this->countMask($size);

        // x86 semantics: count==0 leaves operand and flags unchanged.
        if ($operand === 0) {
            return ExecutionStatus::SUCCESS;
        }

        if ($this->isByteOp($opcode)) {
            $value = $isRegister
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $address);
            if ($operand >= 8) {
                // Count >= width: result becomes 0 (flags are architecturally undefined, but must not crash).
                $result = 0;
                $cfBit = $operand === 8 ? (($value & 0x1) !== 0) : false;
            } else {
                $result = ($value << $operand) & 0xFF;
                // CF is the last bit shifted out
                $cfBit = (($value >> (8 - $operand)) & 0x1) !== 0;
            }

            if ($isRegister) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $address, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($cfBit);
            $runtime->memoryAccessor()->updateFlags($result, 8);
            // OF for SHL by 1: set if MSB changed (MSB XOR CF) - must be after updateFlags
            if ($operand === 1) {
                $msb = ($result >> 7) & 1;
                $runtime->memoryAccessor()->setOverflowFlag($msb !== ($cfBit ? 1 : 0));
            }

            return ExecutionStatus::SUCCESS;
        }

        if ($size === 64) {
            $ma = $runtime->memoryAccessor();
            $valueU = $isRegister
                ? UInt64::of($this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64))
                : $this->readMemory64($runtime, $address);

            $resultU = $valueU->shl($operand);
            $cfBit = ($valueU->shr(64 - $operand)->low32() & 0x1) !== 0;

            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $address, $resultU);
            }

            $ma->setCarryFlag($cfBit);
            $ma->setZeroFlag($resultU->isZero());
            $ma->setSignFlag($resultU->isNegativeSigned());
            $lowByte = $resultU->low32() & 0xFF;
            $ones = 0;
            for ($i = 0; $i < 8; $i++) {
                $ones += ($lowByte >> $i) & 1;
            }
            $ma->setParityFlag(($ones % 2) === 0);
            if ($operand === 1) {
                $ma->setOverflowFlag($resultU->isNegativeSigned() !== $cfBit);
            }

            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $value = $isRegister
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size)
            : ($size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address));

        if ($operand >= $size) {
            $result = 0;
            $cfBit = $operand === $size ? (($value & 0x1) !== 0) : false;
        } else {
            $result = ($value << $operand) & $mask;
            $cfBit = (($value >> ($size - $operand)) & 0x1) !== 0;
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

        $runtime->memoryAccessor()->setCarryFlag($cfBit);
        $runtime->memoryAccessor()->updateFlags($result, $size);
        // OF for SHL by 1: set if MSB changed (MSB XOR CF) - must be after updateFlags
        if ($operand === 1) {
            $msb = ($result >> ($size - 1)) & 1;
            $runtime->memoryAccessor()->setOverflowFlag($msb !== ($cfBit ? 1 : 0));
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function shiftRightLogical(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $operand = $this->count($runtime, $opcode, $memory, $modRegRM) & $this->countMask($size);

        // x86 semantics: count==0 leaves operand and flags unchanged.
        if ($operand === 0) {
            return ExecutionStatus::SUCCESS;
        }

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
            // OF for SHR by 1: set to the original MSB - must be after updateFlags
            if ($operand === 1) {
                $runtime->memoryAccessor()->setOverflowFlag((($value >> 7) & 1) !== 0);
            }

            return ExecutionStatus::SUCCESS;
        }

        if ($size === 64) {
            $ma = $runtime->memoryAccessor();
            $valueU = $isRegister
                ? UInt64::of($this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64))
                : $this->readMemory64($runtime, $address);

            $resultU = $valueU->shr($operand);
            $cfBit = ($valueU->shr($operand - 1)->low32() & 0x1) !== 0;

            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $address, $resultU);
            }

            $ma->setCarryFlag($cfBit);
            $ma->setZeroFlag($resultU->isZero());
            $ma->setSignFlag($resultU->isNegativeSigned());
            $lowByte = $resultU->low32() & 0xFF;
            $ones = 0;
            for ($i = 0; $i < 8; $i++) {
                $ones += ($lowByte >> $i) & 1;
            }
            $ma->setParityFlag(($ones % 2) === 0);

            if ($operand === 1) {
                $ma->setOverflowFlag($valueU->isNegativeSigned());
            }

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
        // OF for SHR by 1: set to the original MSB - must be after updateFlags
        if ($operand === 1) {
            $runtime->memoryAccessor()->setOverflowFlag((($value >> ($size - 1)) & 1) !== 0);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function shiftRightArithmetic(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $operand = $this->count($runtime, $opcode, $memory, $modRegRM) & $this->countMask($size);

        // x86 semantics: count==0 leaves operand and flags unchanged.
        if ($operand === 0) {
            return ExecutionStatus::SUCCESS;
        }

        if ($this->isByteOp($opcode)) {
            $value = $isRegister
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $address);

            // SAR: Arithmetic right shift - sign bit is propagated
            $sign = $value & 0x80;
            if ($operand >= 8) {
                // If shift count >= 8, result is all 1s (if negative) or all 0s (if positive)
                $result = $sign ? 0xFF : 0;
            } else {
                $result = $value >> $operand;
                if ($sign && $operand > 0) {
                    // Fill upper bits with 1s (sign extension)
                    $signFill = ((1 << $operand) - 1) << (8 - $operand);
                    $result = ($result | $signFill) & 0xFF;
                }
            }

            if ($isRegister) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $address, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($operand > 0 ? (($value >> ($operand - 1)) & 0x1) !== 0 : false);
            $runtime->memoryAccessor()->updateFlags($result, 8);
            // OF for SAR by 1: always 0 (sign is always preserved) - must be after updateFlags
            if ($operand === 1) {
                $runtime->memoryAccessor()->setOverflowFlag(false);
            }

            return ExecutionStatus::SUCCESS;
        }

        if ($size === 64) {
            $ma = $runtime->memoryAccessor();
            $valueInt = $isRegister
                ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64)
                : $this->readMemory64($runtime, $address)->toInt();

            $resultInt = $valueInt >> $operand;

            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultInt, 64);
            } else {
                $this->writeMemory64($runtime, $address, $resultInt);
            }

            $ma->setCarryFlag((($valueInt >> ($operand - 1)) & 0x1) !== 0);
            $ma->setZeroFlag($resultInt === 0);
            $ma->setSignFlag($resultInt < 0);
            $lowByte = $resultInt & 0xFF;
            $ones = 0;
            for ($i = 0; $i < 8; $i++) {
                $ones += ($lowByte >> $i) & 1;
            }
            $ma->setParityFlag(($ones % 2) === 0);
            // OF for SAR by 1: always 0 (sign is always preserved)
            if ($operand === 1) {
                $ma->setOverflowFlag(false);
            }

            return ExecutionStatus::SUCCESS;
        }

        $value = $isRegister
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size)
            : ($size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address));

        $signBit = 1 << ($size - 1);
        $sign = $value & $signBit;
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;

        // SAR: Arithmetic right shift - sign bit is propagated
        if ($operand >= $size) {
            // If shift count >= operand size, result is all 1s (if negative) or all 0s (if positive)
            $result = $sign ? $mask : 0;
        } else {
            $result = $value >> $operand;
            if ($sign && $operand > 0) {
                // Fill upper bits with 1s (sign extension)
                $signFill = ((1 << $operand) - 1) << ($size - $operand);
                $result = ($result | $signFill) & $mask;
            }
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
        // OF for SAR by 1: always 0 (sign is always preserved) - must be after updateFlags
        if ($operand === 1) {
            $runtime->memoryAccessor()->setOverflowFlag(false);
        }

        return ExecutionStatus::SUCCESS;
    }
}
