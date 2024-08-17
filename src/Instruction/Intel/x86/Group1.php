<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group1 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x80, 0x81];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException(
                sprintf('The addressing mode (0b%02s) is not supported yet', decbin($modRegRM->mode()))
            );
        }

        $operand = $opcode === 0x80
            ? $enhancedStreamReader
                ->streamReader()
                ->byte()
            : $enhancedStreamReader
                ->short();

        match ($modRegRM->digit()) {
            0x0 => $this->add($runtime, $enhancedStreamReader, $modRegRM, $operand),
            0x1 => $this->or($runtime, $enhancedStreamReader, $modRegRM, $operand),
            0x2 => $this->adc($runtime, $enhancedStreamReader, $modRegRM, $operand),
            0x3 => $this->sbb($runtime, $enhancedStreamReader, $modRegRM, $operand),
            0x4 => $this->and($runtime, $enhancedStreamReader, $modRegRM, $operand),
            0x5 => $this->sub($runtime, $enhancedStreamReader, $modRegRM, $operand),
            0x6 => $this->xor($runtime, $enhancedStreamReader, $modRegRM, $operand),
            0x7 => $this->cmp($runtime, $enhancedStreamReader, $modRegRM, $operand),
        };

        return ExecutionStatus::SUCCESS;
    }

    protected function add(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $operand): ExecutionStatus
    {
        $runtime
            ->memoryAccessor()
            ->add(
                $modRegRM->registerOrMemoryAddress(),
                $operand,
            );
        return ExecutionStatus::SUCCESS;
    }

    protected function or(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $operand): ExecutionStatus
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function adc(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $operand): ExecutionStatus
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function sbb(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $operand): ExecutionStatus
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function and(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $operand): ExecutionStatus
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function sub(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $operand): ExecutionStatus
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function xor(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $operand): ExecutionStatus
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function cmp(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $operand): ExecutionStatus
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }
}
