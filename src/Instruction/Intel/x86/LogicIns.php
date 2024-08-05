<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class LogicIns implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x81];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $modRM = $runtime->streamReader()->byte();

        $mode = ($modRM >> 6) & 0b00000011;
        $extendableOpCode = ($modRM >> 3) & 0b00000111;
        $registerOrMemory = $modRM & 0b00000111;

        if ($mode !== 0b011) {
            throw new ExecutionException(
                sprintf('The addressing mode (0b%s) is not supported yet', decbin($mode))
            );
        }

        $operand1 = $runtime->streamReader()->byte();
        $operand2 = $runtime->streamReader()->byte();
        $operand = (($operand2 & 0b11111111) << 8) + ($operand1 & 0b11111111);


        match ($extendableOpCode) {
            0x0 => $this->add($runtime, $registerOrMemory, $operand2),
            0x1 => $this->or($runtime, $registerOrMemory, $operand2),
            0x2 => $this->adc($runtime, $registerOrMemory, $operand2),
            0x3 => $this->sbb($runtime, $registerOrMemory, $operand2),
            0x4 => $this->and($runtime, $registerOrMemory, $operand2),
            0x5 => $this->sub($runtime, $registerOrMemory, $operand2),
            0x6 => $this->xor($runtime, $registerOrMemory, $operand2),
            0x7 => $this->cmp($runtime, $registerOrMemory, $operand2),
        };

        return ExecutionStatus::SUCCESS;
    }

    protected function add(RuntimeInterface $runtime, int $register, int $value): void
    {
        $runtime
            ->memoryAccessor()
            ->write(
                $register,
                $runtime
                    ->memoryAccessor()
                    ->fetch($register)
                    ->asByte() + $value,
            );
    }

    protected function or(RuntimeInterface $runtime, int $register, int $value): void
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function adc(RuntimeInterface $runtime, int $register, int $value): void
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function sbb(RuntimeInterface $runtime, int $register, int $value): void
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function and(RuntimeInterface $runtime, int $register, int $value): void
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function sub(RuntimeInterface $runtime, int $register, int $value): void
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function xor(RuntimeInterface $runtime, int $register, int $value): void
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }

    protected function cmp(RuntimeInterface $runtime, int $register, int $value): void
    {
        throw new ExecutionException(
            sprintf(
                'The %s was not implemented yet',
                __FUNCTION__,
            ),
        );
    }
}
