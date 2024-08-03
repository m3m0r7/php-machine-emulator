<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Xor_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x31];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $operand = $runtime->streamReader()->byte();

        $mode = ($operand >> 6) & 0b00000111;
        $register = ($operand >> 3) & 0b00000111;
        $registerOrMemory = $operand & 0b00000111;

        if ($mode !== 0b011) {
           throw new ExecutionException(
               sprintf('The addressing mode (0b%s) is not supported yet', decbin($mode))
           );
        }

        $runtime
            ->memoryAccessor()
            ->write(
                $register,
                $runtime->memoryAccessor()->fetch($register) ^ $runtime->memoryAccessor()->fetch($registerOrMemory),
            );

        return ExecutionStatus::SUCCESS;
    }
}