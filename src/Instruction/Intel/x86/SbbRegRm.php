<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class SbbRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x18, 0x19, 0x1A, 0x1B]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $isByte = in_array($opcode, [0x18, 0x1A], true);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $destIsRm = in_array($opcode, [0x18, 0x19], true);
        $borrow = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        // Cache effective address to avoid reading displacement twice
        $rmAddress = null;
        if ($destIsRm && $modRegRM->mode() !== 0b11) {
            $rmAddress = $this->translateLinearWithMmio($runtime, $this->rmLinearAddress($runtime, $memory, $modRegRM), true);
        }

        $src = $isByte
            ? ($destIsRm
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode())
                : $this->readRm8($runtime, $memory, $modRegRM))
            : ($destIsRm
                ? $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize)
                : $this->readRm($runtime, $memory, $modRegRM, $opSize));

        if ($isByte) {
            $dest = $destIsRm
                ? ($rmAddress !== null ? $this->readMemory8($runtime, $rmAddress) : $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress()))
                : $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());
            $calc = $dest - $src - $borrow;
            $maskedResult = $calc & 0xFF;
            if ($destIsRm) {
                if ($rmAddress !== null) {
                    $this->writeMemory8($runtime, $rmAddress, $maskedResult);
                } else {
                    $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $maskedResult);
                }
            } else {
                $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $maskedResult);
            }
            // OF for SBB: set if signs of operands differ and result sign equals subtrahend sign
            $signA = ($dest >> 7) & 1;
            $signB = ($src >> 7) & 1;
            $signR = ($maskedResult >> 7) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            $af = (($dest & 0x0F) - ($src & 0x0F) - $borrow) < 0;
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, 8)
                ->setCarryFlag($calc < 0)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        } else {
            if ($opSize === 64) {
                $ma = $runtime->memoryAccessor();

                $srcU = $src instanceof UInt64 ? $src : UInt64::of($src);

                if ($destIsRm) {
                    $destU = $rmAddress !== null
                        ? $this->readMemory64($runtime, $rmAddress)
                        : UInt64::of($this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64));
                } else {
                    $destU = UInt64::of($this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), 64));
                }

                $tempU = $destU->sub($srcU);
                $resultU = $tempU->sub($borrow);

                if ($destIsRm) {
                    if ($rmAddress !== null) {
                        $this->writeMemory64($runtime, $rmAddress, $resultU);
                    } else {
                        $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultU->toInt(), 64);
                    }
                } else {
                    $this->writeRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $resultU->toInt(), 64);
                }

                $ma->setZeroFlag($resultU->isZero());
                $ma->setSignFlag($resultU->isNegativeSigned());
                $lowByte = $resultU->low32() & 0xFF;
                $ones = 0;
                for ($i = 0; $i < 8; $i++) {
                    $ones += ($lowByte >> $i) & 1;
                }
                $ma->setParityFlag(($ones % 2) === 0);

                $borrow1 = $destU->lt($srcU);
                $borrow2 = $borrow !== 0 && $tempU->lt($borrow);
                $ma->setCarryFlag($borrow1 || $borrow2);

                $subtrahend = $srcU->add($borrow);
                $signMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
                $overflow = !$destU
                    ->xor($subtrahend)
                    ->and($destU->xor($resultU))
                    ->and($signMask)
                    ->isZero();
                $ma->setOverflowFlag($overflow);

                $af = (($destU->low32() & 0x0F) < (($srcU->low32() & 0x0F) + $borrow));
                $ma->setAuxiliaryCarryFlag($af);

                return ExecutionStatus::SUCCESS;
            }

            $dest = $destIsRm
                ? ($rmAddress !== null
                    ? ($opSize === 32 ? $this->readMemory32($runtime, $rmAddress) : $this->readMemory16($runtime, $rmAddress))
                    : $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize))
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);
            $calc = $dest - $src - $borrow;
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signBit = $opSize === 32 ? 31 : 15;
            $maskedResult = $calc & $mask;
            if ($destIsRm) {
                if ($rmAddress !== null) {
                    if ($opSize === 32) {
                        $this->writeMemory32($runtime, $rmAddress, $maskedResult);
                    } else {
                        $this->writeMemory16($runtime, $rmAddress, $maskedResult);
                    }
                } else {
                    $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $maskedResult, $opSize);
                }
            } else {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $maskedResult, $opSize);
            }
            // OF for SBB: set if signs of operands differ and result sign equals subtrahend sign
            $signA = ($dest >> $signBit) & 1;
            $signB = ($src >> $signBit) & 1;
            $signR = ($maskedResult >> $signBit) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            $af = (($dest & 0x0F) - ($src & 0x0F) - $borrow) < 0;
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, $opSize)
                ->setCarryFlag($calc < 0)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        }

        return ExecutionStatus::SUCCESS;
    }
}
