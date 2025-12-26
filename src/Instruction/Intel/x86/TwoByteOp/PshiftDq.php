<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * PSRLDQ/PSLLDQ (0x66 0x0F 0x73 /3 ib, /7 ib)
 * Shift packed 128-bit integer data right/left by imm8 bytes.
 */
class PshiftDq implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes(
            [[0x66, 0x0F, 0x73]],
            [PrefixClass::Address, PrefixClass::Segment, PrefixClass::Lock],
        );
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $this->parsePrefixes($runtime, $opcodes);

        $cpu = $runtime->context()->cpu();
        $memory = $runtime->memory();
        $modrmByte = $memory->byte();
        $modrm = $memory->modRegRM($modrmByte);
        $mod = ModType::from($modrm->mode());

        $op = $modrm->registerOrOPCode() & 0x7;

        if ($mod !== ModType::REGISTER_TO_REGISTER) {
            // Not a valid encoding for PSLLDQ/PSRLDQ. Consume addressing and imm8 and treat as no-op.
            $this->rmLinearAddress($runtime, $memory, $modrm);
            $memory->byte();
            return ExecutionStatus::SUCCESS;
        }

        $imm = $memory->byte() & 0xFF;
        $count = min($imm, 16);

        $rexB = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB();
        $xmmIndex = ($modrm->registerOrMemoryAddress() & 0x7) | ($rexB ? 8 : 0);

        $src = $cpu->getXmm($xmmIndex);
        $bytes = pack('V4', $src[0], $src[1], $src[2], $src[3]);

        $resultBytes = match ($op) {
            0b011 => $this->shiftBytesRight($bytes, $count), // PSRLDQ
            0b111 => $this->shiftBytesLeft($bytes, $count),  // PSLLDQ
            default => $bytes,
        };

        $vals = array_values(unpack('V4', $resultBytes));
        $cpu->setXmm($xmmIndex, [
            ($vals[0] ?? 0) & 0xFFFFFFFF,
            ($vals[1] ?? 0) & 0xFFFFFFFF,
            ($vals[2] ?? 0) & 0xFFFFFFFF,
            ($vals[3] ?? 0) & 0xFFFFFFFF,
        ]);

        return ExecutionStatus::SUCCESS;
    }

    private function shiftBytesLeft(string $bytes, int $count): string
    {
        if ($count <= 0) {
            return $bytes;
        }
        if ($count >= 16) {
            return str_repeat("\0", 16);
        }
        // Little-endian: shift left inserts zeros at low bytes.
        return str_repeat("\0", $count) . substr($bytes, 0, 16 - $count);
    }

    private function shiftBytesRight(string $bytes, int $count): string
    {
        if ($count <= 0) {
            return $bytes;
        }
        if ($count >= 16) {
            return str_repeat("\0", 16);
        }
        // Little-endian: shift right inserts zeros at high bytes.
        return substr($bytes, $count) . str_repeat("\0", $count);
    }
}

