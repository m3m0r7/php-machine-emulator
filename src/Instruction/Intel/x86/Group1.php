<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class Group1 implements InstructionInterface
{
    use Instructable;
    use GroupRmOperand;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x80, 0x81, 0x82, 0x83]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $ip = $runtime->memory()->offset();
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $size = $runtime->context()->cpu()->operandSize();

        // For memory operands, consume displacement BEFORE reading immediate
        // x86 encoding order: opcode, modrm, displacement, immediate
        [$isReg, $linearAddr] = $this->resolveRmLocation($runtime, $memory, $modRegRM);
        $linearAddr ??= 0;

        // NOW read the immediate value (after displacement has been consumed)
        $operand = $this->isSignExtendedWordOperation($opcode)
            ? $memory->signedByte()
            : ($this->isByteOperation($opcode)
                ? $memory->byte()
                : ($size === 16 ? $memory->short() : $memory->signedDword()));

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

    private function updateCommonFlags64(RuntimeInterface $runtime, UInt64 $result): void
    {
        $ma = $runtime->memoryAccessor();
        $ma->setZeroFlag($result->isZero());
        $ma->setSignFlag($result->isNegativeSigned());

        $lowByte = $result->low32() & 0xFF;
        $ones = 0;
        for ($i = 0; $i < 8; $i++) {
            $ones += ($lowByte >> $i) & 1;
        }
        $ma->setParityFlag(($ones % 2) === 0);
    }

    private function effectiveOpSize(int $opcode, int $opSize): int
    {
        return $this->isByteOperation($opcode) ? 8 : $opSize;
    }

    private function maskForSize(int $size): int
    {
        return match ($size) {
            8 => 0xFF,
            16 => 0xFFFF,
            default => 0xFFFFFFFF,
        };
    }

    private function signBitForSize(int $size): int
    {
        return match ($size) {
            8 => 7,
            16 => 15,
            default => 31,
        };
    }

    private function updateAddFlags(RuntimeInterface $runtime, int $left, int $right, int $result, int $size, int $carry): void
    {
        $mask = $this->maskForSize($size);
        $signBit = $this->signBitForSize($size);
        $rightWithCarry = ($right + $carry) & $mask;

        $signA = ($left >> $signBit) & 1;
        $signB = ($rightWithCarry >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;
        $of = ($signA === $signB) && ($signA !== $signR);
        $af = (($left & 0x0F) + ($right & 0x0F) + $carry) > 0x0F;

        $runtime->memoryAccessor()
            ->updateFlags($result, $size)
            ->setCarryFlag(($left + $right + $carry) > $mask)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);
    }

    private function updateSubFlags(RuntimeInterface $runtime, int $left, int $right, int $result, int $size, int $borrow): void
    {
        $mask = $this->maskForSize($size);
        $signBit = $this->signBitForSize($size);
        $rightWithBorrow = ($right + $borrow) & $mask;

        $signA = ($left >> $signBit) & 1;
        $signB = ($rightWithBorrow >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        $af = (($left & 0x0F) - ($right & 0x0F) - $borrow) < 0;

        $runtime->memoryAccessor()
            ->updateFlags($result, $size)
            ->setCarryFlag(($left - $right - $borrow) < 0)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);
    }

    private function applyLogicOp(
        RuntimeInterface $runtime,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        int $operand,
        int $size,
        bool $isReg,
        int $linearAddr,
        string $op,
    ): ExecutionStatus {
        if ($size === 64) {
            $ma = $runtime->memoryAccessor();
            $regType = $this->rmGprRegisterType($runtime, $modRegRM);
            $leftU = $isReg
                ? UInt64::of($this->readRegisterBySize($runtime, $regType, 64))
                : $this->readMemory64($runtime, $linearAddr);
            $opU = UInt64::of($operand);

            $resultU = match ($op) {
                'or' => $leftU->or($opU),
                'and' => $leftU->and($opU),
                'xor' => $leftU->xor($opU),
                default => $leftU,
            };

            if ($isReg) {
                $this->writeRegisterBySize($runtime, $regType, $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $linearAddr, $resultU);
            }

            $this->updateCommonFlags64($runtime, $resultU);
            $ma->setCarryFlag(false);
            $ma->setOverflowFlag(false);
            $ma->setAuxiliaryCarryFlag(false);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->maskForSize($size);
        $left = (int) $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $size);
        $left &= $mask;
        $opMasked = $operand & $mask;

        $result = match ($op) {
            'or' => $left | $opMasked,
            'and' => $left & $opMasked,
            'xor' => $left ^ $opMasked,
            default => $left,
        };
        $result &= $mask;

        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $result, $size);
        $runtime->memoryAccessor()
            ->updateFlags($result, $size)
            ->setCarryFlag(false)
            ->setOverflowFlag(false)
            ->setAuxiliaryCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function add(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $size = $this->effectiveOpSize($opcode, $opSize);
        if ($size === 64) {
            $ma = $runtime->memoryAccessor();
            $regType = $this->rmGprRegisterType($runtime, $modRegRM);
            $leftU = $isReg
                ? UInt64::of($this->readRegisterBySize($runtime, $regType, 64))
                : $this->readMemory64($runtime, $linearAddr);
            $opU = UInt64::of($operand);

            $resultU = $leftU->add($opU);

            if ($isReg) {
                $this->writeRegisterBySize($runtime, $regType, $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $linearAddr, $resultU);
            }

            $this->updateCommonFlags64($runtime, $resultU);

            $ma->setCarryFlag($resultU->lt($leftU));
            $signMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
            $overflow = !$leftU
                ->xor($resultU)
                ->and($opU->xor($resultU))
                ->and($signMask)
                ->isZero();
            $ma->setOverflowFlag($overflow);
            $af = (($leftU->low32() & 0x0F) + ($opU->low32() & 0x0F)) > 0x0F;
            $ma->setAuxiliaryCarryFlag($af);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->maskForSize($size);
        $left = (int) $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $size);
        $left &= $mask;
        $right = $operand & $mask;
        $result = ($left + $right) & $mask;
        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $result, $size);
        $this->updateAddFlags($runtime, $left, $right, $result, $size, 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function or(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $size = $this->effectiveOpSize($opcode, $opSize);
        return $this->applyLogicOp($runtime, $memory, $modRegRM, $operand, $size, $isReg, $linearAddr, 'or');
    }

    protected function adc(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $carry = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        $size = $this->effectiveOpSize($opcode, $opSize);
        if ($size === 64) {
            $ma = $runtime->memoryAccessor();
            $regType = $this->rmGprRegisterType($runtime, $modRegRM);
            $leftU = $isReg
                ? UInt64::of($this->readRegisterBySize($runtime, $regType, 64))
                : $this->readMemory64($runtime, $linearAddr);
            $opU = UInt64::of($operand);

            $tempU = $leftU->add($opU);
            $resultU = $tempU->add($carry);

            if ($isReg) {
                $this->writeRegisterBySize($runtime, $regType, $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $linearAddr, $resultU);
            }

            $this->updateCommonFlags64($runtime, $resultU);

            $carry1 = $tempU->lt($leftU);
            $carry2 = $carry !== 0 && $resultU->lt($tempU);
            $ma->setCarryFlag($carry1 || $carry2);

            $srcU = $opU->add($carry);
            $signMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
            $overflow = !$leftU
                ->xor($resultU)
                ->and($srcU->xor($resultU))
                ->and($signMask)
                ->isZero();
            $ma->setOverflowFlag($overflow);

            $af = (($leftU->low32() & 0x0F) + ($opU->low32() & 0x0F) + $carry) > 0x0F;
            $ma->setAuxiliaryCarryFlag($af);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->maskForSize($size);
        $left = (int) $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $size);
        $left &= $mask;
        $right = $operand & $mask;
        $result = ($left + $right + $carry) & $mask;
        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $result, $size);
        $this->updateAddFlags($runtime, $left, $right, $result, $size, $carry);

        return ExecutionStatus::SUCCESS;
    }

    protected function sbb(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $borrow = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        $size = $this->effectiveOpSize($opcode, $opSize);
        if ($size === 64) {
            $ma = $runtime->memoryAccessor();
            $regType = $this->rmGprRegisterType($runtime, $modRegRM);
            $leftU = $isReg
                ? UInt64::of($this->readRegisterBySize($runtime, $regType, 64))
                : $this->readMemory64($runtime, $linearAddr);
            $opU = UInt64::of($operand);

            $tempU = $leftU->sub($opU);
            $resultU = $tempU->sub($borrow);

            if ($isReg) {
                $this->writeRegisterBySize($runtime, $regType, $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $linearAddr, $resultU);
            }

            $this->updateCommonFlags64($runtime, $resultU);

            $borrow1 = $leftU->lt($opU);
            $borrow2 = $borrow !== 0 && $tempU->lt($borrow);
            $ma->setCarryFlag($borrow1 || $borrow2);

            $srcU = $opU->add($borrow);
            $signMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
            $overflow = !$leftU
                ->xor($srcU)
                ->and($leftU->xor($resultU))
                ->and($signMask)
                ->isZero();
            $ma->setOverflowFlag($overflow);

            $af = (($leftU->low32() & 0x0F) < (($opU->low32() & 0x0F) + $borrow));
            $ma->setAuxiliaryCarryFlag($af);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->maskForSize($size);
        $left = (int) $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $size);
        $left &= $mask;
        $right = $operand & $mask;
        $result = ($left - $right - $borrow) & $mask;
        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $result, $size);
        $this->updateSubFlags($runtime, $left, $right, $result, $size, $borrow);

        return ExecutionStatus::SUCCESS;
    }

    protected function and(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $size = $this->effectiveOpSize($opcode, $opSize);
        return $this->applyLogicOp($runtime, $memory, $modRegRM, $operand, $size, $isReg, $linearAddr, 'and');
    }

    protected function sub(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $size = $this->effectiveOpSize($opcode, $opSize);
        if ($size === 64) {
            $ma = $runtime->memoryAccessor();
            $regType = $this->rmGprRegisterType($runtime, $modRegRM);
            $leftU = $isReg
                ? UInt64::of($this->readRegisterBySize($runtime, $regType, 64))
                : $this->readMemory64($runtime, $linearAddr);
            $opU = UInt64::of($operand);

            $resultU = $leftU->sub($opU);

            if ($isReg) {
                $this->writeRegisterBySize($runtime, $regType, $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $linearAddr, $resultU);
            }

            $this->updateCommonFlags64($runtime, $resultU);

            $ma->setCarryFlag($leftU->lt($opU));
            $signMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
            $overflow = !$leftU
                ->xor($opU)
                ->and($leftU->xor($resultU))
                ->and($signMask)
                ->isZero();
            $ma->setOverflowFlag($overflow);

            $af = (($leftU->low32() & 0x0F) < ($opU->low32() & 0x0F));
            $ma->setAuxiliaryCarryFlag($af);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->maskForSize($size);
        $left = (int) $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $size);
        $left &= $mask;
        $right = $operand & $mask;
        $result = ($left - $right) & $mask;
        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $result, $size);
        $this->updateSubFlags($runtime, $left, $right, $result, $size, 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function xor(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $size = $this->effectiveOpSize($opcode, $opSize);
        return $this->applyLogicOp($runtime, $memory, $modRegRM, $operand, $size, $isReg, $linearAddr, 'xor');
    }

    protected function cmp(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize, bool $isReg, int $linearAddr): ExecutionStatus
    {
        $size = $this->effectiveOpSize($opcode, $opSize);
        if ($size === 64) {
            $ma = $runtime->memoryAccessor();
            $regType = $this->rmGprRegisterType($runtime, $modRegRM);
            $leftU = $isReg
                ? UInt64::of($this->readRegisterBySize($runtime, $regType, 64))
                : $this->readMemory64($runtime, $linearAddr);
            $opU = UInt64::of($operand);

            $resultU = $leftU->sub($opU);

            $this->updateCommonFlags64($runtime, $resultU);
            $ma->setCarryFlag($leftU->lt($opU));

            $signMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
            $overflow = !$leftU
                ->xor($opU)
                ->and($leftU->xor($resultU))
                ->and($signMask)
                ->isZero();
            $ma->setOverflowFlag($overflow);

            $af = (($leftU->low32() & 0x0F) < ($opU->low32() & 0x0F));
            $ma->setAuxiliaryCarryFlag($af);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->maskForSize($size);
        $left = (int) $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isReg, $linearAddr, $size);
        $left &= $mask;
        $right = $operand & $mask;
        $result = ($left - $right) & $mask;

        if ($size === 8) {
            // Debug: check if this is the flag check at CS:0x137
            $debugIP = $runtime->memory()->offset();
            if ($debugIP >= 0x9FAF0 && $debugIP <= 0x9FB00 && $right === 0x00) {
                $segOverride = $runtime->context()->cpu()->segmentOverride();
                $cs = $runtime->memoryAccessor()->fetch(\PHPMachineEmulator\Instruction\RegisterType::CS)->asByte();
                $runtime->option()->logger()->debug(sprintf(
                    'CMP DEBUG: afterIP=0x%05X linearAddr=0x%05X left=0x%02X isReg=%d segOverride=%s CS=0x%04X',
                    $debugIP,
                    $linearAddr,
                    $left,
                    $isReg ? 1 : 0,
                    $segOverride?->name ?? 'none',
                    $cs
                ));
            }
        }

        $this->updateSubFlags($runtime, $left, $right, $result, $size, 0);

        if ($size === 8) {
            $runtime->option()->logger()->debug(sprintf(
                'CMP r/m8, imm8: left=0x%02X right=0x%02X ZF=%d',
                $left,
                $right,
                $left === $right ? 1 : 0
            ));
        } else {
            $runtime->option()->logger()->debug(sprintf(
                'CMP r/m%d, imm: left=0x%04X right=0x%04X ZF=%d',
                $size,
                $left,
                $right,
                $left === $right ? 1 : 0
            ));
        }

        return ExecutionStatus::SUCCESS;
    }
}
