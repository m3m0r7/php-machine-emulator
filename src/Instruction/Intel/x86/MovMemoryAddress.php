<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovMemoryAddress implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x8A];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        if ($modRegRM->mode() !== 0b000) {
            throw new ExecutionException(
                sprintf('The addressing mode (0b%s) is not supported yet', decbin($modRegRM->mode()))
            );
        }

        $runtime
            ->memoryAccessor()
            ->write(
                $modRegRM->destination(),
                $runtime
                    ->memoryAccessor()
                    ->fetch($modRegRM->source())
                    ->asByte(),
            );

        return ExecutionStatus::SUCCESS;
    }
}
