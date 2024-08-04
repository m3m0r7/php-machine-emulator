<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Movsg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x8E];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $operand = $runtime->streamReader()->byte();

        $mode = ($operand >> 6) & 0b00000111;
        $to = ($operand >> 3) & 0b00000111;
        $from = $operand & 0b00000111;

        if ($mode !== 0b011) {
            throw new ExecutionException(
                sprintf('The addressing mode (0b%s) is not supported yet', decbin($mode))
            );
        }

        $runtime
            ->memoryAccessor()
            ->write(
                $to + ($runtime->register())::getRaisedSegmentRegister(),
                $runtime
                    ->memoryAccessor()
                    ->fetch($from)
                    ->asByte(),
            );

        return ExecutionStatus::SUCCESS;
    }
}
