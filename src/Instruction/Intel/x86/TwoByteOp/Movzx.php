<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * MOVZX (0x0F 0xB6 / 0x0F 0xB7)
 * Move with zero-extension.
 * 0xB6: MOVZX r16/32, r/m8
 * 0xB7: MOVZX r32, r/m16
 */
class Movzx implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            [0x0F, 0xB6], // MOVZX r16/32, r/m8
            [0x0F, 0xB7], // MOVZX r32, r/m16
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();

        // Determine if byte or word source based on opcode
        $isByte = ($opcode & 0xFF) === 0xB6 || ($opcode === 0x0FB6);

        // Debug: log before reading for problem IP range
        $ip = $runtime->memory()->offset();
        $isDebugRange = $ip >= 0x1009C0 && $ip <= 0x1009E0;

        $value = $isByte
            ? $this->readRm8($runtime, $reader, $modrm)
            : $this->readRm16($runtime, $reader, $modrm);

        $destReg = $modrm->registerOrOPCode();

        // Debug: log MOVZX details
        if ($isDebugRange) {
            $runtime->option()->logger()->debug(sprintf(
                'MOVZX: IP=0x%04X isByte=%d destReg=%d value=0x%08X opSize=%d mode=%d rm=%d',
                $ip, $isByte ? 1 : 0, $destReg, $value, $opSize, $modrm->mode(), $modrm->registerOrMemoryAddress()
            ));
        }

        $this->writeRegisterBySize($runtime, $destReg, $value, $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
