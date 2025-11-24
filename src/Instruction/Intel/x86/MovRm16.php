<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovRm16 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x8B];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        $value = $this->readRm16($runtime, $enhancedStreamReader, $modRegRM);

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->write16Bit(
                $modRegRM->registerOrOPCode(),
                $value,
            );

        return ExecutionStatus::SUCCESS;
    }
}
