<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovImmToRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xC6, 0xC7];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();
        $size = $runtime->runtimeOption()->context()->operandSize();

        if ($modRegRM->registerOrOPCode() !== 0) {
            throw new ExecutionException('Invalid MOV immediate to r/m digit');
        }

        if ($opcode === 0xC6) {
            $value = $enhancedStreamReader->streamReader()->byte();
            $this->writeRm8($runtime, $enhancedStreamReader, $modRegRM, $value);
        } else {
            $value = $size === 32 ? $enhancedStreamReader->dword() : $enhancedStreamReader->short();
            $this->writeRm($runtime, $enhancedStreamReader, $modRegRM, $value, $size);
        }

        return ExecutionStatus::SUCCESS;
    }
}
