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
 * MOVD/MOVQ between XMM and r/m (SSE2).
 *
 * - MOVD xmm, r/m32: 66 0F 6E /r
 * - MOVD r/m32, xmm: 66 0F 7E /r
 * - MOVQ xmm, r/m64: 66 REX.W 0F 6E /r
 * - MOVQ r/m64, xmm: 66 REX.W 0F 7E /r
 */
class MovdMovq implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes(
            [
                [0x66, 0x0F, 0x6E],
                [0x66, 0x0F, 0x7E],
            ],
            [PrefixClass::Address, PrefixClass::Segment, PrefixClass::Lock],
        );
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $secondByte = $opcode & 0xFF;

        $cpu = $runtime->context()->cpu();
        $size = ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexW()) ? 64 : 32;

        $memory = $runtime->memory();
        $modrmByte = $memory->byte();
        $modrm = $memory->modRegRM($modrmByte);
        $mod = ModType::from($modrm->mode());

        $rexR = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexR();
        $rexB = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB();

        $xmmIndex = ($modrm->registerOrOPCode() & 0x7) | ($rexR ? 8 : 0);

        if ($secondByte === 0x6E) {
            // xmm, r/m32 (or r/m64 with REX.W)
            if ($mod === ModType::REGISTER_TO_REGISTER) {
                $rmCode = $modrm->registerOrMemoryAddress() & 0x7;
                $gpr = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
                    ? Register::findGprByCode($rmCode, $rexB)
                    : $rmCode;

                if ($size === 64) {
                    $srcU = UInt64::of($this->readRegisterBySize($runtime, $gpr, 64));
                    $cpu->setXmm($xmmIndex, [$srcU->low32(), $srcU->high32(), 0, 0]);
                } else {
                    $src32 = $this->readRegisterBySize($runtime, $gpr, 32) & 0xFFFFFFFF;
                    $cpu->setXmm($xmmIndex, [$src32, 0, 0, 0]);
                }

                return ExecutionStatus::SUCCESS;
            }

            $address = $this->rmLinearAddress($runtime, $memory, $modrm);
            if ($size === 64) {
                $srcU = $this->readMemory64($runtime, $address);
                $cpu->setXmm($xmmIndex, [$srcU->low32(), $srcU->high32(), 0, 0]);
            } else {
                $src32 = $this->readMemory32($runtime, $address) & 0xFFFFFFFF;
                $cpu->setXmm($xmmIndex, [$src32, 0, 0, 0]);
            }

            return ExecutionStatus::SUCCESS;
        }

        // r/m32 (or r/m64 with REX.W), xmm
        $srcXmm = $cpu->getXmm($xmmIndex);

        if ($mod === ModType::REGISTER_TO_REGISTER) {
            $rmCode = $modrm->registerOrMemoryAddress() & 0x7;
            $gpr = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
                ? Register::findGprByCode($rmCode, $rexB)
                : $rmCode;

            if ($size === 64) {
                $valueU = UInt64::fromParts($srcXmm[0], $srcXmm[1]);
                $this->writeRegisterBySize($runtime, $gpr, $valueU->toInt(), 64);
            } else {
                $this->writeRegisterBySize($runtime, $gpr, $srcXmm[0] & 0xFFFFFFFF, 32);
            }

            return ExecutionStatus::SUCCESS;
        }

        $address = $this->rmLinearAddress($runtime, $memory, $modrm);
        if ($size === 64) {
            $valueU = UInt64::fromParts($srcXmm[0], $srcXmm[1]);
            $this->writeMemory64($runtime, $address, $valueU);
        } else {
            $this->writeMemory32($runtime, $address, $srcXmm[0] & 0xFFFFFFFF);
        }

        return ExecutionStatus::SUCCESS;
    }
}

