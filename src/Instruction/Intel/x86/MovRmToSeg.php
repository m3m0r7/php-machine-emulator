<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovRmToSeg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x8E];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        $seg = $this->segmentFromDigit($modRegRM->registerOrOPCode());
        $value = $this->readRm16($runtime, $reader, $modRegRM);

        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($seg, $value);

        // Debug: track DS/SS changes
        if ($seg === RegisterType::DS) {
            $runtime->option()->logger()->debug(sprintf(
                'DS changed to 0x%04X at offset 0x%05X',
                $value,
                $runtime->streamReader()->offset()
            ));
        }
        if ($seg === RegisterType::SS) {
            $esp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(
                $runtime->context()->cpu()->operandSize()
            );
            $runtime->option()->logger()->debug(sprintf(
                'SS changed to 0x%04X at offset 0x%05X (ESP=0x%05X, new stack linear=0x%05X)',
                $value,
                $runtime->streamReader()->offset(),
                $esp,
                ($value << 4) + $esp
            ));
        }

        return ExecutionStatus::SUCCESS;
    }

    private function segmentFromDigit(int $digit): RegisterType
    {
        return match ($digit & 0b111) {
            0b000 => RegisterType::ES,
            0b001 => RegisterType::CS,
            0b010 => RegisterType::SS,
            0b011 => RegisterType::DS,
            0b100 => RegisterType::FS,
            0b101 => RegisterType::GS,
            default => throw new ExecutionException('Invalid segment register encoding'),
        };
    }
}
