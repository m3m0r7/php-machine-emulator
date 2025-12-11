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
 * SETcc (0x0F 0x90-0x9F)
 * Set byte on condition.
 */
class Setcc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        $opcodes = [];
        for ($i = 0x90; $i <= 0x9F; $i++) {
            $opcodes[] = [0x0F, $i];
        }
        return $this->applyPrefixes($opcodes);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();

        $cc = ($opcode & 0xFF) & 0x0F;
        if ($opcode > 0xFF) {
            $cc = $opcode & 0x0F;
        }

        $val = $this->conditionMet($runtime, $cc) ? 1 : 0;

        if (ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER) {
            $this->write8BitRegister($runtime, $modrm->registerOrMemoryAddress(), $val);
        } else {
            $addr = $this->rmLinearAddress($runtime, $memory, $modrm);
            $this->writeMemory8($runtime, $addr, $val);
        }

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
