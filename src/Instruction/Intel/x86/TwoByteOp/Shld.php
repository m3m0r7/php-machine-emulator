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
        return [
            [0x0F, 0xA4], // SHLD r/m, r, imm8
            [0x0F, 0xA5], // SHLD r/m, r, CL
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        $isImm = ($opcode & 0xFF) === 0xA4 || ($opcode === 0x0FA4);

        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modrm);

        // Read count after displacement is consumed
        $count = $isImm
            ? ($reader->streamReader()->byte() & 0x1F)
            : ($runtime->memoryAccessor()->fetch(RegisterType::ECX)->asLowBit() & 0x1F);

        if ($count === 0) {
            return ExecutionStatus::SUCCESS;
        }

        $dest = $isRegister
            ? $this->readRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $dest &= $mask;

        $src = $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize) & $mask;
        $result = (($dest << $count) | ($src >> ($opSize - $count))) & $mask;
        $cf = ($dest >> ($opSize - $count)) & 0x1;

        $of = false;
        if ($count === 1) {
            $msb = ($result >> ($opSize - 1)) & 1;
            $next = ($result >> ($opSize - 2)) & 1;
            $of = ($msb ^ $next) === 1;
        }

        $ma = $runtime->memoryAccessor();
        $ma->setCarryFlag($cf === 1)->updateFlags($result, $opSize);
        $ma->setOverflowFlag($of);

        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $result, $opSize);
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
