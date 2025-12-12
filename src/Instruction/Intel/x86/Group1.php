<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group1 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x80, 0x81, 0x82, 0x83]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $ip = $runtime->memory()->offset();
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $size = $runtime->context()->cpu()->operandSize();
        $isReg = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

        // For memory operands, consume displacement BEFORE reading immediate
        // x86 encoding order: opcode, modrm, displacement, immediate
        $linearAddr = !$isReg ? $this->rmLinearAddress($runtime, $memory, $modRegRM) : 0;

        // NOW read the immediate value (after displacement has been consumed)
        $operand = $this->isSignExtendedWordOperation($opcode)
            ? $memory->signedByte()
            : ($this->isByteOperation($opcode)
                ? $memory->byte()
                : ($size === 32 ? $memory->dword() : $memory->short()));

        match ($modRegRM->digit()) {
            0x0 => $this->add($runtime, $memory, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x1 => $this->or($runtime, $memory, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x2 => $this->adc($runtime, $memory, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x3 => $this->sbb($runtime, $memory, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x4 => $this->and($runtime, $memory, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x5 => $this->sub($runtime, $memory, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x6 => $this->xor($runtime, $memory, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x7 => $this->cmp($runtime, $memory, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
        };

        return ExecutionStatus::SUCCESS;
    }

    private function isByteOperation(int $opcode): bool
    {
        return $opcode === 0x80 || $opcode === 0x82;
    }

    private function isSignExtendedWordOperation(int $opcode): bool
    {
        return $opcode === 0x83;
    }

    protected function add(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $original = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $op = $operand & 0xFF;
            $result = $original + $op;
            $maskedResult = $result & 0xFF;
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $maskedResult);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $maskedResult);
            }
            // OF for ADD: set if signs of operands are same but result sign differs
            $signA = ($original >> 7) & 1;
            $signB = ($op >> 7) & 1;
            $signR = ($maskedResult >> 7) & 1;
            $of = ($signA === $signB) && ($signA !== $signR);
            // AF: carry from bit 3 to bit 4
            $af = (($original & 0x0F) + ($op & 0x0F)) > 0x0F;
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, 8)
                ->setCarryFlag($result > 0xFF)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $signBit = $opSize === 32 ? 31 : 15;
        $original = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));

        // For sign-extended operand, convert to unsigned for proper carry detection
        $unsignedOperand = $operand & $mask;
        $result = $original + $unsignedOperand;
        $maskedResult = $result & $mask;

        if ($isReg) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $maskedResult, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $maskedResult);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $maskedResult);
            }
        }
        // OF for ADD: set if signs of operands are same but result sign differs
        $signA = ($original >> $signBit) & 1;
        $signB = ($unsignedOperand >> $signBit) & 1;
        $signR = ($maskedResult >> $signBit) & 1;
        $of = ($signA === $signB) && ($signA !== $signR);
        // AF: carry from bit 3 to bit 4
        $af = (($original & 0x0F) + ($unsignedOperand & 0x0F)) > 0x0F;
        $runtime->memoryAccessor()
            ->updateFlags($maskedResult, $opSize)
            ->setCarryFlag($result > $mask)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);

        return ExecutionStatus::SUCCESS;
    }

    protected function or(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $original = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $result = $original | ($operand & 0xFF);
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
            $runtime->memoryAccessor()
                ->updateFlags($result, 8)
                ->setCarryFlag(false)
                ->setOverflowFlag(false)
                ->setAuxiliaryCarryFlag(false);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $result = ($left | $operand) & $mask;

        if ($isReg) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
        }
        $runtime->memoryAccessor()
            ->updateFlags($result, $opSize)
            ->setCarryFlag(false)
            ->setOverflowFlag(false)
            ->setAuxiliaryCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function adc(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $carry = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        if ($this->isByteOperation($opcode)) {
            $left = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $op = $operand & 0xFF;
            $result = $left + $op + $carry;
            $maskedResult = $result & 0xFF;
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $maskedResult);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $maskedResult);
            }
            // OF for ADC: set if signs of operands are same but result sign differs
            $signA = ($left >> 7) & 1;
            $signB = ($op >> 7) & 1;
            $signR = ($maskedResult >> 7) & 1;
            $of = ($signA === $signB) && ($signA !== $signR);
            // AF: carry from bit 3 to bit 4
            $af = (($left & 0x0F) + ($op & 0x0F) + $carry) > 0x0F;
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, 8)
                ->setCarryFlag($result > 0xFF)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $signBit = $opSize === 32 ? 31 : 15;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $unsignedOperand = $operand & $mask;
        $result = $left + $unsignedOperand + $carry;
        $maskedResult = $result & $mask;

        if ($isReg) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $maskedResult, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $maskedResult);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $maskedResult);
            }
        }
        // OF for ADC: set if signs of operands are same but result sign differs
        $signA = ($left >> $signBit) & 1;
        $signB = ($unsignedOperand >> $signBit) & 1;
        $signR = ($maskedResult >> $signBit) & 1;
        $of = ($signA === $signB) && ($signA !== $signR);
        // AF: carry from bit 3 to bit 4
        $af = (($left & 0x0F) + ($unsignedOperand & 0x0F) + $carry) > 0x0F;
        $runtime->memoryAccessor()
            ->updateFlags($maskedResult, $opSize)
            ->setCarryFlag($result > $mask)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);

        return ExecutionStatus::SUCCESS;
    }

    protected function sbb(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $borrow = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        if ($this->isByteOperation($opcode)) {
            $left = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $op = $operand & 0xFF;
            $calc = $left - $op - $borrow;
            $result = $calc & 0xFF;
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
            // OF for SUB/SBB: set if signs of operands differ and result sign equals subtrahend sign
            $signA = ($left >> 7) & 1;
            $signB = ($op >> 7) & 1;
            $signR = ($result >> 7) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            // AF: borrow from bit 4 to bit 3
            $af = (($left & 0x0F) - ($op & 0x0F) - $borrow) < 0;
            $runtime->memoryAccessor()
                ->updateFlags($result, 8)
                ->setCarryFlag($calc < 0)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $signBit = $opSize === 32 ? 31 : 15;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        // Convert sign-extended operand to unsigned for proper borrow calculation
        $unsignedOperand = $operand & $mask;
        $calc = $left - $unsignedOperand - $borrow;
        $result = $calc & $mask;

        if ($isReg) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
        }
        // OF for SUB/SBB: set if signs of operands differ and result sign equals subtrahend sign
        $signA = ($left >> $signBit) & 1;
        $signB = ($unsignedOperand >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        // AF: borrow from bit 4 to bit 3
        $af = (($left & 0x0F) - ($unsignedOperand & 0x0F) - $borrow) < 0;
        $runtime->memoryAccessor()
            ->updateFlags($result, $opSize)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);

        return ExecutionStatus::SUCCESS;
    }

    protected function and(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $left = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $result = $left & ($operand & 0xFF);
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
            $runtime->memoryAccessor()
                ->updateFlags($result, 8)
                ->setCarryFlag(false)
                ->setOverflowFlag(false)
                ->setAuxiliaryCarryFlag(false);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $result = ($left & $operand) & $mask;

        if ($isReg) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
        }
        $runtime->memoryAccessor()
            ->updateFlags($result, $opSize)
            ->setCarryFlag(false)
            ->setOverflowFlag(false)
            ->setAuxiliaryCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function sub(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $left = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $op = $operand & 0xFF;
            $calc = $left - $op;
            $result = $calc & 0xFF;
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
            // OF for SUB: set if signs of operands differ and result sign equals subtrahend sign
            $signA = ($left >> 7) & 1;
            $signB = ($op >> 7) & 1;
            $signR = ($result >> 7) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            // AF: borrow from bit 4 to bit 3
            $af = (($left & 0x0F) - ($op & 0x0F)) < 0;
            $runtime->memoryAccessor()
                ->updateFlags($result, 8)
                ->setCarryFlag($calc < 0)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $signBit = $opSize === 32 ? 31 : 15;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        // Convert sign-extended operand to unsigned for proper borrow calculation
        $unsignedOperand = $operand & $mask;
        $calc = $left - $unsignedOperand;
        $result = $calc & $mask;

        if ($isReg) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
        }
        // OF for SUB: set if signs of operands differ and result sign equals subtrahend sign
        $signA = ($left >> $signBit) & 1;
        $signB = ($unsignedOperand >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        // AF: borrow from bit 4 to bit 3
        $af = (($left & 0x0F) - ($unsignedOperand & 0x0F)) < 0;
        $runtime->memoryAccessor()
            ->updateFlags($result, $opSize)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);

        return ExecutionStatus::SUCCESS;
    }

    protected function xor(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $left = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $result = $left ^ ($operand & 0xFF);
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
            $runtime->memoryAccessor()
                ->updateFlags($result, 8)
                ->setCarryFlag(false)
                ->setOverflowFlag(false)
                ->setAuxiliaryCarryFlag(false);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $result = ($left ^ $operand) & $mask;

        if ($isReg) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
        }
        $runtime->memoryAccessor()
            ->updateFlags($result, $opSize)
            ->setCarryFlag(false)
            ->setOverflowFlag(false)
            ->setAuxiliaryCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function cmp(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $left = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $left &= 0xFF;
            $op = $operand & 0xFF;

            // Debug: check if this is the flag check at CS:0x137
            $debugIP = $runtime->memory()->offset();
            if ($debugIP >= 0x9FAF0 && $debugIP <= 0x9FB00 && $op === 0x00) {
                $segOverride = $runtime->context()->cpu()->segmentOverride();
                $cs = $runtime->memoryAccessor()->fetch(\PHPMachineEmulator\Instruction\RegisterType::CS)->asByte();
                $runtime->option()->logger()->warning(sprintf(
                    'CMP DEBUG: afterIP=0x%05X linearAddr=0x%05X left=0x%02X isReg=%d segOverride=%s CS=0x%04X',
                    $debugIP, $linearAddr, $left, $isReg ? 1 : 0, $segOverride?->name ?? 'none', $cs
                ));
            }

            $calc = $left - $op;
            $result = $calc & 0xFF;
            // OF for CMP (same as SUB): set if signs of operands differ and result sign equals subtrahend sign
            $signA = ($left >> 7) & 1;
            $signB = ($op >> 7) & 1;
            $signR = ($result >> 7) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            // AF: borrow from bit 4 to bit 3
            $af = (($left & 0x0F) - ($op & 0x0F)) < 0;
            $runtime
                ->memoryAccessor()
                ->updateFlags($result, 8)
                ->setCarryFlag($calc < 0)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
            $runtime->option()->logger()->debug(sprintf('CMP r/m8, imm8: left=0x%02X right=0x%02X ZF=%d', $left, $op, $left === $op ? 1 : 0));

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $signBit = $opSize === 32 ? 31 : 15;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        // Convert sign-extended operand to unsigned for proper borrow calculation
        $unsignedOperand = $operand & $mask;
        $calc = $left - $unsignedOperand;
        $result = $calc & $mask;
        // OF for CMP (same as SUB): set if signs of operands differ and result sign equals subtrahend sign
        $signA = ($left >> $signBit) & 1;
        $signB = ($unsignedOperand >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        // AF: borrow from bit 4 to bit 3
        $af = (($left & 0x0F) - ($unsignedOperand & 0x0F)) < 0;
        $runtime
            ->memoryAccessor()
            ->updateFlags($result, $opSize)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);
        $runtime->option()->logger()->debug(sprintf('CMP r/m%d, imm: left=0x%04X right=0x%04X ZF=%d', $opSize, $left, $unsignedOperand, $left === $unsignedOperand ? 1 : 0));

        return ExecutionStatus::SUCCESS;
    }
}
