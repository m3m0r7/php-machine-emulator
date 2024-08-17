<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group2 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xC0];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader
            ->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException(
                sprintf('The addressing mode (0b%02s) is not supported yet', decbin($modRegRM->mode()))
            );
        }

        return match ($modRegRM->digit()) {
            0x5 => $this->rotateRight($runtime, $modRegRM),
            default => throw new ExecutionException(
                sprintf('The digit (0b%s) is not supported yet', decbin($modRegRM->digit()))
            ),
        };
    }

    protected function rotateRight(RuntimeInterface $runtime, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $operand = $runtime->streamReader()->byte();

        $value = $runtime
            ->memoryAccessor()
            ->fetch($modRegRM->source())
            ->asLowBit();

        $runtime
            ->memoryAccessor()
            ->writeToLowBit(
                $modRegRM->source(),
                $value >> $operand,
            )
            ->setCarryFlag(($value & 0b00000001) !== 0);

        return ExecutionStatus::SUCCESS;
    }
}
