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

class Group3 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF7];
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

        match ($modRegRM->digit()) {
            0x6 => $this->div($runtime, $enhancedStreamReader, $modRegRM),
            default => throw new ExecutionException(
                sprintf(
                    'The %s#%d was not implemented yet',
                    __CLASS__,
                    $modRegRM->digit(),
                ),
            ),
        };

        return ExecutionStatus::SUCCESS;
    }


    protected function div(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $divider = $runtime
            ->memoryAccessor()
            ->fetch($modRegRM->source())
            ->asByte();

        $dividee = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EAX)
            ->asByte();

        $quotient = (int) ($dividee / $divider);
        $remainder = $dividee % $divider;

        $runtime
            ->memoryAccessor()
            ->write(
                RegisterType::EAX,
                $quotient,
            );

        $runtime
            ->memoryAccessor()
            ->write(
                RegisterType::EDX,
                $remainder,
            );

        return ExecutionStatus::SUCCESS;
    }
}
