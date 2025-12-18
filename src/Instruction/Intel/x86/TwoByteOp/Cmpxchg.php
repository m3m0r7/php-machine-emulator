<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

/**
 * CMPXCHG (0x0F 0xB0 / 0x0F 0xB1)
 * Compare and exchange.
 * 0xB0: CMPXCHG r/m8, r8
 * 0xB1: CMPXCHG r/m16/32, r16/32
 */
class Cmpxchg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([
            [0x0F, 0xB0], // CMPXCHG r/m8, r8
            [0x0F, 0xB1], // CMPXCHG r/m16/32, r16/32
        ], [PrefixClass::Operand, PrefixClass::Address, PrefixClass::Segment, PrefixClass::Lock]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();

        $isByte = ($opcode & 0xFF) === 0xB0 || ($opcode === 0x0FB0);
        $cpu = $runtime->context()->cpu();
        $opSize = $isByte ? 8 : $cpu->operandSize();

        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modrm);

        $ma = $runtime->memoryAccessor();
        $acc = match ($opSize) {
            8 => $ma->fetch(RegisterType::EAX)->asLowBit(),
            16 => $ma->fetch(RegisterType::EAX)->asBytesBySize(16),
            32 => $ma->fetch(RegisterType::EAX)->asBytesBySize(32),
            64 => $ma->fetch(RegisterType::EAX)->asBytesBySize(64),
            default => $ma->fetch(RegisterType::EAX)->asBytesBySize(32),
        };

        if ($isRegister) {
            $rmCode = $modrm->registerOrMemoryAddress();
            if ($opSize === 8) {
                $dest = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()
                    ? $this->read8BitRegister64($runtime, $rmCode, true, $cpu->rexB())
                    : $this->read8BitRegister($runtime, $rmCode);
            } else {
                $rmReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
                    ? Register::findGprByCode($rmCode, $cpu->rexB())
                    : $rmCode;
                $dest = $this->readRegisterBySize($runtime, $rmReg, $opSize);
            }
        } else {
            $dest = match ($opSize) {
                8 => $this->readMemory8($runtime, $linearAddr),
                16 => $this->readMemory16($runtime, $linearAddr),
                32 => $this->readMemory32($runtime, $linearAddr),
                64 => $this->readMemory64($runtime, $linearAddr),
                default => $this->readMemory32($runtime, $linearAddr),
            };
        }

        $regCode = $modrm->registerOrOPCode();
        if ($opSize === 8) {
            $src = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()
                ? $this->read8BitRegister64($runtime, $regCode, true, $cpu->rexR())
                : $this->read8BitRegister($runtime, $regCode);
        } else {
            $reg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
                ? Register::findGprByCode($regCode, $cpu->rexR())
                : $regCode;
            $src = $this->readRegisterBySize($runtime, $reg, $opSize);
        }

        // Flags as a subtraction acc - dest (like CMP acc, dest)
        if ($opSize === 64) {
            $accU = UInt64::of($acc);
            $destU = $dest instanceof UInt64 ? $dest : UInt64::of($dest);
            $resultU = $accU->sub($destU);

            $ma->setZeroFlag($resultU->isZero());
            $ma->setSignFlag($resultU->isNegativeSigned());
            $lowByte = $resultU->low32() & 0xFF;
            $ones = 0;
            for ($i = 0; $i < 8; $i++) {
                $ones += ($lowByte >> $i) & 1;
            }
            $ma->setParityFlag(($ones % 2) === 0);

            $ma->setCarryFlag($accU->lt($destU));
            $signMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
            $overflow = !$accU
                ->xor($destU)
                ->and($accU->xor($resultU))
                ->and($signMask)
                ->isZero();
            $ma->setOverflowFlag($overflow);
            $af = !$accU->xor($destU)->xor($resultU)->and(0x10)->isZero();
            $ma->setAuxiliaryCarryFlag($af);
        } else {
            $mask = match ($opSize) {
                8 => 0xFF,
                16 => 0xFFFF,
                32 => 0xFFFFFFFF,
                default => 0xFFFFFFFF,
            };
            $signBit = match ($opSize) {
                8 => 7,
                16 => 15,
                32 => 31,
                default => 31,
            };
            $accU = $acc & $mask;
            $destU = ($dest instanceof UInt64 ? $dest->toInt() : $dest) & $mask;
            $calc = $accU - $destU;
            $maskedResult = $calc & $mask;
            $cf = $calc < 0;
            $af = (($accU & 0x0F) < ($destU & 0x0F));
            $signA = ($accU >> $signBit) & 1;
            $signB = ($destU >> $signBit) & 1;
            $signR = ($maskedResult >> $signBit) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            $ma->updateFlags($maskedResult, $opSize)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        }

        $isEqual = $ma->shouldZeroFlag();
        if ($isEqual) {
            if ($opSize === 8) {
                if ($isRegister) {
                    if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
                        $this->write8BitRegister64($runtime, $modrm->registerOrMemoryAddress(), $src, true, $cpu->rexB());
                    } else {
                        $this->write8BitRegister($runtime, $modrm->registerOrMemoryAddress(), $src);
                    }
                } else {
                    $this->writeMemory8($runtime, $linearAddr, $src);
                }
            } else {
                if ($isRegister) {
                    $destReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
                        ? Register::findGprByCode($modrm->registerOrMemoryAddress(), $cpu->rexB())
                        : $modrm->registerOrMemoryAddress();
                    $this->writeRegisterBySize($runtime, $destReg, $src, $opSize);
                } else {
                    match ($opSize) {
                        16 => $this->writeMemory16($runtime, $linearAddr, $src),
                        32 => $this->writeMemory32($runtime, $linearAddr, $src),
                        64 => $this->writeMemory64($runtime, $linearAddr, UInt64::of($src)),
                        default => $this->writeMemory32($runtime, $linearAddr, $src),
                    };
                }
            }
        } else {
            if ($opSize === 8) {
                $ma->writeToLowBit(RegisterType::EAX, ($dest instanceof UInt64 ? $dest->low32() : $dest) & 0xFF);
            } else {
                $destInt = $dest instanceof UInt64 ? $dest->toInt() : $dest;
                $this->writeRegisterBySize($runtime, RegisterType::EAX, $destInt, $opSize);
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
