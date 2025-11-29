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
                ->memory()
            ->signedByte();

        $pos = $runtime
                ->memory()
            ->offset();

        // LOOP decrements ECX first, then checks if non-zero
        $size = $runtime->context()->cpu()->addressSize();
        $counter = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::ECX)
            ->asBytesBySize($size);

        // Optimization: Empty loop detection (LOOP to itself or very small backward jump)
        // If operand is -2 (jump back to the LOOP instruction itself), skip entire loop
        if ($operand === -2 && $counter > 1) {
            // This is a delay loop - skip it entirely
            $runtime->memoryAccessor()
                ->enableUpdateFlags(false)
                ->writeBySize(RegisterType::ECX, 0, $size);
            return ExecutionStatus::SUCCESS;
        }

        $counter = ($counter - 1) & ($size === 32 ? 0xFFFFFFFF : 0xFFFF);

        // Write decremented value back
        $runtime->memoryAccessor()
            ->writeBySize(RegisterType::ECX, $counter, $size);

        $runtime->option()->logger()->debug(sprintf('LOOP: counter=%d, operand=%d, pos=0x%X, target=0x%X',
            $counter, $operand, $pos, $pos + $operand));

        // Jump if counter is non-zero
        if ($counter === 0) {
            return ExecutionStatus::SUCCESS;
        }

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime
                ->memory()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
