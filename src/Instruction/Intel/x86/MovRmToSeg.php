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
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        $seg = $this->segmentFromDigit($modRegRM->registerOrOPCode());
        $value = $this->readRm16($runtime, $reader, $modRegRM);

        $runtime->option()->logger()->debug(sprintf(
            'MOV %s, rm16: value=0x%04X',
            $seg->name,
            $value
        ));

        $runtime->memoryAccessor()->write16Bit($seg, $value);

        if ($seg === RegisterType::SS) {
            $esp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(
                $runtime->context()->cpu()->operandSize()
            );
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
