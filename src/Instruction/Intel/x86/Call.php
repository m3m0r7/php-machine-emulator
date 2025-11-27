<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Call implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE8];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());

        $offset = $runtime->context()->cpu()->operandSize() === 32
            ? $enhancedStreamReader->signedDword()
            : $enhancedStreamReader->signedShort();

        $pos = $runtime->streamReader()->offset();

        // Push return address onto stack.
        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->push(RegisterType::ESP, $pos, $runtime->context()->cpu()->operandSize());

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime
                ->streamReader()
                ->setOffset($pos + $offset);
        }

        return ExecutionStatus::SUCCESS;
    }
}
