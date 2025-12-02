<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * CMOVcc (0x0F 0x40-0x4F)
 * Conditional move.
 */
class Cmovcc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        $opcodes = [];
        for ($i = 0x40; $i <= 0x4F; $i++) {
            $opcodes[] = [0x0F, $i];
        }
        return $opcodes;
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();

        $cc = ($opcode & 0xFF) & 0x0F;
        if ($opcode > 0xFF) {
            $cc = $opcode & 0x0F;
        }

        if (!$this->conditionMet($runtime, $cc)) {
            // Still consume addressing but don't perform move
            if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
                $this->rmLinearAddress($runtime, $reader, $modrm);
            }
            return ExecutionStatus::SUCCESS;
        }

        $value = $this->readRm($runtime, $reader, $modrm, $opSize);
        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $value, $opSize);

        return ExecutionStatus::SUCCESS;
    }

    private function conditionMet(RuntimeInterface $runtime, int $cc): bool
    {
        $ma = $runtime->memoryAccessor();
        $zf = $ma->shouldZeroFlag();
        $cf = $ma->shouldCarryFlag();
        $sf = $ma->shouldSignFlag();
        $of = $ma->shouldOverflowFlag();
        $pf = $ma->shouldParityFlag();

        return match ($cc) {
            0x0 => $of,
            0x1 => !$of,
            0x2 => $cf,
            0x3 => !$cf,
            0x4 => $zf,
            0x5 => !$zf,
            0x6 => $cf || $zf,
            0x7 => !$cf && !$zf,
            0x8 => $sf,
            0x9 => !$sf,
            0xA => $pf,
            0xB => !$pf,
            0xC => $sf !== $of,
            0xD => $sf === $of,
            0xE => $zf || ($sf !== $of),
            0xF => !$zf && ($sf === $of),
            default => false,
        };
    }
}
