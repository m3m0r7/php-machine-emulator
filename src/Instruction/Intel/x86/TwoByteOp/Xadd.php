<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

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
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();
        $ma = $runtime->memoryAccessor();

        $isByte = ($opcode & 0xFF) === 0xC0 || ($opcode === 0x0FC0);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $mask = $opSize === 32 ? 0xFFFFFFFF : (($opSize === 16) ? 0xFFFF : 0xFF);

        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modrm);

        if ($isByte) {
            $dest = $isRegister
                ? $this->read8BitRegister($runtime, $modrm->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
        } else {
            $dest = $isRegister
                ? $this->readRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $opSize)
                : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        }

        $src = $isByte
            ? $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), 8)
            : $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize);

        $sum = $dest + $src;
        $result = $sum & $mask;
        $ma->setCarryFlag($sum > $mask)->updateFlags($result, $opSize);

        // Write result
        if ($isByte) {
            if ($isRegister) {
                $this->write8BitRegister($runtime, $modrm->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
        } else {
            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $result, $opSize);
            } else {
                if ($opSize === 32) {
                    $this->writeMemory32($runtime, $linearAddr, $result);
                } else {
                    $this->writeMemory16($runtime, $linearAddr, $result);
                }
            }
        }

        // Source register receives original destination value
        if ($isByte) {
            $this->write8BitRegister($runtime, $modrm->registerOrOPCode(), $dest);
        } else {
            $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $dest, $opSize);
        }

        return ExecutionStatus::SUCCESS;
    }
}
