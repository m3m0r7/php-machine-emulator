<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovSegToRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x8C];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        $seg = $this->segmentFromDigit($modRegRM->registerOrOPCode());
        $value = $runtime->memoryAccessor()->fetch($seg)->asByte();

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $runtime->memoryAccessor()->write16Bit($modRegRM->registerOrMemoryAddress(), $value);
        } else {
            $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
            $runtime->memoryAccessor()->allocate($address, safe: false);
            $runtime->memoryAccessor()->write16Bit($address, $value);
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
