<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group1 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            0x80,
            0x81,
            0x82,
            0x83,
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        $size = $runtime->context()->cpu()->operandSize();
        $isReg = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

        // For memory operands, consume displacement BEFORE reading immediate
        // x86 encoding order: opcode, modrm, displacement, immediate
        $linearAddr = !$isReg ? $this->rmLinearAddress($runtime, $enhancedStreamReader, $modRegRM) : 0;

        // NOW read the immediate value (after displacement has been consumed)
        $operand = $this->isSignExtendedWordOperation($opcode)
            ? $enhancedStreamReader->streamReader()->signedByte()
            : ($this->isByteOperation($opcode)
                ? $enhancedStreamReader->streamReader()->byte()
                : ($size === 32 ? $enhancedStreamReader->dword() : $enhancedStreamReader->short()));

        match ($modRegRM->digit()) {
            0x0 => $this->add($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x1 => $this->or($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x2 => $this->adc($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x3 => $this->sbb($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x4 => $this->and($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x5 => $this->sub($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x6 => $this->xor($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
            0x7 => $this->cmp($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size, $isReg, $linearAddr),
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

    protected function add(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $original = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $result = $original + $operand;
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($result > 0xFF);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $original = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));

        $result = $original + $operand;

        if ($isReg) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
            // Debug: show memory write for ADD
            $runtime->option()->logger()->debug(sprintf('ADD [0x%05X], 0x%04X: original=0x%04X result=0x%04X', $linearAddr, $operand, $original, $result & 0xFFFF));
        }
        $runtime->memoryAccessor()->setCarryFlag($result > $mask);

        return ExecutionStatus::SUCCESS;
    }

    protected function or(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
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
            $runtime->memoryAccessor()->setCarryFlag(false);

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
        $runtime->memoryAccessor()->setCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function adc(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $carry = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        if ($this->isByteOperation($opcode)) {
            $left = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $result = $left + ($operand & 0xFF) + $carry;
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($result > 0xFF);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $result = $left + $operand + $carry;

        if ($isReg) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
        }
        $runtime->memoryAccessor()->setCarryFlag($result > $mask);

        return ExecutionStatus::SUCCESS;
    }

    protected function sbb(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $borrow = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        if ($this->isByteOperation($opcode)) {
            $left = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $result = ($left - ($operand & 0xFF) - $borrow) & 0xFF;
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag(($left - ($operand & 0xFF) - $borrow) < 0);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $calc = $left - $operand - $borrow;
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
        $runtime->memoryAccessor()->setCarryFlag($calc < 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function and(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
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
            $runtime->memoryAccessor()->setCarryFlag(false);

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
        $runtime->memoryAccessor()->setCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function sub(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $left = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $result = ($left - ($operand & 0xFF)) & 0xFF;
            if ($isReg) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
            $runtime->memoryAccessor()->setCarryFlag(($left - ($operand & 0xFF)) < 0);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $left = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $calc = $left - $operand;
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
        $runtime->memoryAccessor()->setCarryFlag($calc < 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function xor(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
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
            $runtime->memoryAccessor()->setCarryFlag(false);

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
        $runtime->memoryAccessor()->setCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function cmp(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $leftHand = $isReg
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
            $leftHand &= 0xFF;
            $runtime
                ->memoryAccessor()
                ->updateFlags($leftHand - ($operand & 0xFF), 8)
                ->setCarryFlag($leftHand < ($operand & 0xFF));

            return ExecutionStatus::SUCCESS;
        }

        $leftHand = $isReg
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $newCF = $leftHand < $operand;

        $runtime
            ->memoryAccessor()
            ->updateFlags($leftHand - $operand, $opSize)
            ->setCarryFlag($newCF);

        return ExecutionStatus::SUCCESS;
    }
}
