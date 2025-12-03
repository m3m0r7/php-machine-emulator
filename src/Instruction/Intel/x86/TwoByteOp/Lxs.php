<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
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
        return [
            [0x0F, 0xB2], // LSS
            [0x0F, 0xB4], // LFS
            [0x0F, 0xB5], // LGS
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();
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

        $address = $this->rmLinearAddress($runtime, $reader, $modrm);

        $offset = $opSize === 32
            ? $this->readMemory32($runtime, $address)
            : $this->readMemory16($runtime, $address);
        $segValue = $this->readMemory16($runtime, $address + ($opSize === 32 ? 4 : 2));

        // Debug: trace LSS/LFS/LGS
        $segName = match ($secondByte) {
            0xB2 => 'SS',
            0xB4 => 'FS',
            0xB5 => 'GS',
            default => '??',
        };
        $runtime->option()->logger()->debug(sprintf(
            'L%sS: address=0x%08X offset=0x%08X seg=0x%04X opSize=%d',
            $segName, $address, $offset, $segValue, $opSize
        ));

        $destReg = $modrm->registerOrOPCode();
        $this->writeRegisterBySize($runtime, $destReg, $offset, $opSize);
        $runtime->memoryAccessor()->write16Bit($segment, $segValue & 0xFFFF);

        // Debug: if LSS, show final ESP value
        if ($secondByte === 0xB2) {
            $finalEsp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(32);
            $runtime->option()->logger()->debug(sprintf(
                'LSS: destReg=%d ESP after=0x%08X',
                $destReg, $finalEsp
            ));
        }

        return ExecutionStatus::SUCCESS;
    }
}
