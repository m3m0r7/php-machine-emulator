<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Or_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x08];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException(
                sprintf('The addressing mode (0b%s) is not supported yet', decbin($modRegRM->mode()))
            );
        }

        $runtime
            ->memoryAccessor()
            ->writeToLowBit(
                $modRegRM->destination(),
                $runtime
                    ->memoryAccessor()
                    ->fetch($modRegRM->destination())
                    ->asLowBit() | $runtime
                    ->memoryAccessor()
                    ->fetch($modRegRM->source())
                    ->asLowBit(),
            );

        return ExecutionStatus::SUCCESS;
    }
}
