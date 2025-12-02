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
 * NOP r/m16/32 (0x0F 0x1F)
 * Multi-byte NOP.
 */
class NopModrm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [[0x0F, 0x1F]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();

        if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
            $this->rmLinearAddress($runtime, $reader, $modrm); // consume displacement/SIB
        }

        return ExecutionStatus::SUCCESS;
    }
}
