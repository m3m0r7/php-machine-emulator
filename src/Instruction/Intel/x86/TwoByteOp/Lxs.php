<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * LSS/LFS/LGS (0x0F 0xB2 / 0x0F 0xB4 / 0x0F 0xB5)
 * Load far pointer.
 */
class Lxs implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([
            [0x0F, 0xB2], // LSS
            [0x0F, 0xB4], // LFS
            [0x0F, 0xB5], // LGS
        ], [PrefixClass::Operand, PrefixClass::Address, PrefixClass::Segment]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();

        $secondByte = $opcode & 0xFF;
        if ($opcode > 0xFF) {
            $secondByte = $opcode & 0xFF;
        }

        $segment = match ($secondByte) {
            0xB2 => RegisterType::SS,
            0xB4 => RegisterType::FS,
            0xB5 => RegisterType::GS,
            default => RegisterType::DS,
        };

        $address = $this->rmLinearAddress($runtime, $memory, $modrm);

        $offset = $opSize === 32
            ? $this->readMemory32($runtime, $address)
            : $this->readMemory16($runtime, $address);
        $segValue = $this->readMemory16($runtime, $address + ($opSize === 32 ? 4 : 2));

        $destReg = $modrm->registerOrOPCode();
        $this->writeRegisterBySize($runtime, $destReg, $offset, $opSize);
        $cpu = $runtime->context()->cpu();

        if ($cpu->isProtectedMode() && $segValue !== 0) {
            $descriptor = $this->readSegmentDescriptor($runtime, $segValue);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $cpu->cacheSegmentDescriptor($segment, $descriptor);
            }
        }

        $runtime->memoryAccessor()->write16Bit($segment, $segValue & 0xFFFF);

        if (!$cpu->isProtectedMode()) {
            $cpu->cacheSegmentDescriptor($segment, [
                'base' => ((($segValue & 0xFFFF) << 4) & 0xFFFFF),
                'limit' => 0xFFFF,
                'present' => true,
                'type' => 0,
                'system' => false,
                'executable' => false,
                'dpl' => 0,
                'default' => 16,
            ]);
        }

        if ($segment === RegisterType::SS) {
            // LSS blocks interrupts for the following instruction.
            $runtime->context()->cpu()->blockInterruptDelivery(1);
        }

        return ExecutionStatus::SUCCESS;
    }
}
