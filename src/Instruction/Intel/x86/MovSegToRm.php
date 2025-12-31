<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\InvalidOpcodeException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovSegToRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x8C]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $opcode = $opcodes[array_key_last($opcodes)] ?? 0x8C;
        $seg = $this->segmentFromDigit($modRegRM->registerOrOPCode(), $opcode);
        $value = $runtime->memoryAccessor()->fetch($seg)->asByte();

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $runtime->memoryAccessor()->write16Bit($modRegRM->registerOrMemoryAddress(), $value);
        } else {
            $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
            $this->writeMemory16($runtime, $address, $value);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function segmentFromDigit(int $digit, int $opcode): RegisterType
    {
        return match ($digit & 0b111) {
            0b000 => RegisterType::ES,
            0b001 => RegisterType::CS,
            0b010 => RegisterType::SS,
            0b011 => RegisterType::DS,
            0b100 => RegisterType::FS,
            0b101 => RegisterType::GS,
            default => throw new InvalidOpcodeException(
                $opcode & 0xFF,
                sprintf('Invalid segment register encoding (reg=%d)', $digit & 0b111)
            ),
        };
    }
}
