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
 * SHLD (0x0F 0xA4 / 0x0F 0xA5)
 * Double precision shift left.
 * 0xA4: SHLD r/m16/32, r16/32, imm8
 * 0xA5: SHLD r/m16/32, r16/32, CL
 */
class Shld implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([
            [0x0F, 0xA4], // SHLD r/m, r, imm8
            [0x0F, 0xA5], // SHLD r/m, r, CL
        ], [PrefixClass::Operand, PrefixClass::Address, PrefixClass::Segment]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();
        $cpu = $runtime->context()->cpu();
        $opSize = $cpu->operandSize();
        $countMask = $opSize === 64 ? 0x3F : 0x1F;

        $isImm = ($opcode & 0xFF) === 0xA4 || ($opcode === 0x0FA4);

        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modrm);

        // Read count after displacement is consumed
        $count = $isImm
            ? ($memory->byte() & $countMask)
            : ($runtime->memoryAccessor()->fetch(RegisterType::ECX)->asLowBit() & $countMask);

        if ($count === 0) {
            return ExecutionStatus::SUCCESS;
        }

        $destRegCode = $modrm->registerOrMemoryAddress();
        $destReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
            ? Register::findGprByCode($destRegCode, $cpu->rexB())
            : $destRegCode;
        $srcRegCode = $modrm->registerOrOPCode();
        $srcReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
            ? Register::findGprByCode($srcRegCode, $cpu->rexR())
            : $srcRegCode;

        if ($opSize === 64) {
            $destU = $isRegister
                ? UInt64::of($this->readRegisterBySize($runtime, $destReg, 64))
                : $this->readMemory64($runtime, $linearAddr);
            $srcU = UInt64::of($this->readRegisterBySize($runtime, $srcReg, 64));

            $resultU = $destU->shl($count)->or($srcU->shr(64 - $count));
            $cfBit = ($destU->shr(64 - $count)->low32() & 0x1) !== 0;

            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $destReg, $resultU->toInt(), 64);
            } else {
                $this->writeMemory64($runtime, $linearAddr, $resultU);
            }

            $resultInt = $resultU->toInt();
            $ma = $runtime->memoryAccessor();
            $ma->setCarryFlag($cfBit)->updateFlags($resultInt, 64);
            if ($count === 1) {
                $msb = $resultU->isNegativeSigned();
                $ma->setOverflowFlag($msb !== $cfBit);
            }

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        $dest = $isRegister
            ? $this->readRegisterBySize($runtime, $destReg, $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $dest &= $mask;

        $src = $this->readRegisterBySize($runtime, $srcReg, $opSize) & $mask;

        if ($opSize === 16) {
            // Intel-defined behaviour for count > 16 uses A:B:A.
            $triple = (($dest & 0xFFFF) << 32) | (($src & 0xFFFF) << 16) | ($dest & 0xFFFF);
            $result = ((($triple << $count) >> 32) & 0xFFFF);
            $cf = (($triple >> (48 - $count)) & 0x1) !== 0;
        } else {
            $result = (($dest << $count) | ($src >> (32 - $count))) & $mask;
            $cf = (($dest >> (32 - $count)) & 0x1) !== 0;
        }

        $ma = $runtime->memoryAccessor();
        $ma->setCarryFlag($cf)->updateFlags($result, $opSize);
        if ($count === 1) {
            $msb = ($result >> ($opSize - 1)) & 1;
            $ma->setOverflowFlag($msb !== ($cf ? 1 : 0));
        }

        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $destReg, $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
