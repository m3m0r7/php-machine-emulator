<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

/**
 * XADD (0x0F 0xC0 / 0x0F 0xC1)
 * Exchange and add.
 * 0xC0: XADD r/m8, r8
 * 0xC1: XADD r/m16/32, r16/32
 */
class Xadd implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([
            [0x0F, 0xC0], // XADD r/m8, r8
            [0x0F, 0xC1], // XADD r/m16/32, r16/32
        ], [PrefixClass::Operand, PrefixClass::Address, PrefixClass::Segment, PrefixClass::Lock]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();
        $ma = $runtime->memoryAccessor();
        $cpu = $runtime->context()->cpu();

        $isByte = ($opcode & 0xFF) === 0xC0 || ($opcode === 0x0FC0);
        $opSize = $isByte ? 8 : $cpu->operandSize();

        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modrm);

        $rmCode = $modrm->registerOrMemoryAddress();
        $rmReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
            ? Register::findGprByCode($rmCode, $cpu->rexB())
            : $rmCode;
        $regCode = $modrm->registerOrOPCode();
        $reg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
            ? Register::findGprByCode($regCode, $cpu->rexR())
            : $regCode;

        if ($opSize === 64) {
            $destU = $isRegister
                ? UInt64::of($this->readRegisterBySize($runtime, $rmReg, 64))
                : $this->readMemory64($runtime, $linearAddr);
            $srcU = UInt64::of($this->readRegisterBySize($runtime, $reg, 64));

            $resultU = $destU->add($srcU);

            $ma->setZeroFlag($resultU->isZero());
            $ma->setSignFlag($resultU->isNegativeSigned());
            $lowByte = $resultU->low32() & 0xFF;
            $ones = 0;
            for ($i = 0; $i < 8; $i++) {
                $ones += ($lowByte >> $i) & 1;
            }
            $ma->setParityFlag(($ones % 2) === 0);

            $ma->setCarryFlag($resultU->lt($destU));
            $signMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
            $overflow = !$destU
                ->xor($resultU)
                ->and($srcU->xor($resultU))
                ->and($signMask)
                ->isZero();
            $ma->setOverflowFlag($overflow);
            $af = !$destU->xor($srcU)->xor($resultU)->and(0x10)->isZero();
            $ma->setAuxiliaryCarryFlag($af);

            // Write result to destination
            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $rmReg, $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $linearAddr, $resultU);
            }

            // Source register receives original destination value
            $this->writeRegisterBySize($runtime, $reg, $destU->toInt(), 64);
            return ExecutionStatus::SUCCESS;
        }

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

        if ($opSize === 8) {
            if ($isRegister) {
                $dest = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()
                    ? $this->read8BitRegister64($runtime, $rmCode, true, $cpu->rexB())
                    : $this->read8BitRegister($runtime, $rmCode);
            } else {
                $dest = $this->readMemory8($runtime, $linearAddr);
            }

            $src = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()
                ? $this->read8BitRegister64($runtime, $regCode, true, $cpu->rexR())
                : $this->read8BitRegister($runtime, $regCode);
        } else {
            $dest = $isRegister
                ? $this->readRegisterBySize($runtime, $rmReg, $opSize)
                : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
            $src = $this->readRegisterBySize($runtime, $reg, $opSize);
        }

        $sum = $dest + $src;
        $result = $sum & $mask;
        $cf = $sum > $mask;
        $signA = ($dest >> $signBit) & 1;
        $signB = ($src >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;
        $of = ($signA === $signB) && ($signA !== $signR);
        $af = (($dest & 0x0F) + ($src & 0x0F)) > 0x0F;
        $ma->updateFlags($result, $opSize)
            ->setCarryFlag($cf)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);

        // Write result
        if ($opSize === 8) {
            if ($isRegister) {
                if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
                    $this->write8BitRegister64($runtime, $rmCode, $result, true, $cpu->rexB());
                } else {
                    $this->write8BitRegister($runtime, $rmCode, $result);
                }
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }

            // Source register receives original destination value
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
                $this->write8BitRegister64($runtime, $regCode, $dest, true, $cpu->rexR());
            } else {
                $this->write8BitRegister($runtime, $regCode, $dest);
            }
        } else {
            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $rmReg, $result, $opSize);
            } else {
                if ($opSize === 32) {
                    $this->writeMemory32($runtime, $linearAddr, $result);
                } else {
                    $this->writeMemory16($runtime, $linearAddr, $result);
                }
            }

            // Source register receives original destination value
            $this->writeRegisterBySize($runtime, $reg, $dest, $opSize);
        }

        return ExecutionStatus::SUCCESS;
    }
}
