<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Loop implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE2];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        // LOOP decrements ECX first, then checks if non-zero
        $size = $runtime->context()->cpu()->addressSize();
        $counter = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::ECX)
            ->asBytesBySize($size);

        $counter = ($counter - 1) & ($size === 32 ? 0xFFFFFFFF : 0xFFFF);

        // Write decremented value back
        $runtime->memoryAccessor()
            ->writeBySize(RegisterType::ECX, $counter, $size);

        // Jump if counter is non-zero
        if ($counter === 0) {
            return ExecutionStatus::SUCCESS;
        }

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime
                ->streamReader()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
