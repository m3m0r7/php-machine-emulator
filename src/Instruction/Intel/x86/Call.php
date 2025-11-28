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
        $espBefore = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(
            $runtime->context()->cpu()->operandSize()
        );

        $opSize = $runtime->context()->cpu()->operandSize();
        $target = $pos + $offset;
        $runtime->option()->logger()->debug(sprintf('CALL near: pos=0x%05X offset=0x%04X target=0x%05X (push return=0x%05X, ESP before=0x%08X)', $pos, $offset & 0xFFFF, $target, $pos, $espBefore));

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->push(RegisterType::ESP, $pos, $opSize);

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime
                ->streamReader()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
