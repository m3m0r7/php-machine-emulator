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

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $modRM = $runtime->streamReader()->byte();

        $mode = ($modRM >> 6) & 0b00000011;
        $register = ($modRM >> 3) & 0b00000111;
        $registerOrMemory = $modRM & 0b00000111;

        if ($mode !== 0b011) {
           throw new ExecutionException(
               sprintf('The addressing mode (0b%02s) is not supported yet', decbin($mode))
           );
        }

        $runtime
            ->memoryAccessor()
            ->write16Bit(
                $register,
                $runtime->memoryAccessor()->fetch($register)->asByte() ^ $runtime->memoryAccessor()->fetch($registerOrMemory)->asByte(),
            );

        return ExecutionStatus::SUCCESS;
    }
}
