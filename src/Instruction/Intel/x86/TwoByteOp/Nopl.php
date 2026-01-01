<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * NOP r/m16/32/64, r16/32/64 (0x0F 0x1E /r)
 *
 * Multi-byte NOP used for alignment. When prefixed with 0xF3 and specific ModR/M
 * encodings it overlaps with ENDBR32/ENDBR64 (CET), which we treat as NOP.
 */
class Nopl implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0x1E]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();

        if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
            $this->rmLinearAddress($runtime, $memory, $modrm); // consume displacement/SIB
        }

        return ExecutionStatus::SUCCESS;
    }
}
