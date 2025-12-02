<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

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
        return [
            [0x0F, 0xB0], // CMPXCHG r/m8, r8
            [0x0F, 0xB1], // CMPXCHG r/m16/32, r16/32
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();

        $isByte = ($opcode & 0xFF) === 0xB0 || ($opcode === 0x0FB0);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $mask = $opSize === 32 ? 0xFFFFFFFF : (($opSize === 16) ? 0xFFFF : 0xFF);

        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modrm);

        $ma = $runtime->memoryAccessor();
        $acc = $isByte
            ? $ma->fetch(RegisterType::EAX)->asLowBit()
            : $ma->fetch(RegisterType::EAX)->asBytesBySize($opSize);

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

        // Flags as a subtraction dest - acc
        $ma->updateFlags($dest - $acc, $opSize)->setCarryFlag($dest < $acc);

        if ($dest === ($acc & $mask)) {
            $ma->setZeroFlag(true);
            if ($isByte) {
                if ($isRegister) {
                    $this->write8BitRegister($runtime, $modrm->registerOrMemoryAddress(), $src);
                } else {
                    $this->writeMemory8($runtime, $linearAddr, $src);
                }
            } else {
                if ($isRegister) {
                    $this->writeRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $src, $opSize);
                } else {
                    if ($opSize === 32) {
                        $this->writeMemory32($runtime, $linearAddr, $src);
                    } else {
                        $this->writeMemory16($runtime, $linearAddr, $src);
                    }
                }
            }
        } else {
            $ma->setZeroFlag(false);
            if ($isByte) {
                $ma->writeToLowBit(RegisterType::EAX, $dest & 0xFF);
            } else {
                $this->writeRegisterBySize($runtime, RegisterType::EAX, $dest & $mask, $opSize);
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
