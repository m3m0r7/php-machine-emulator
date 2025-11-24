<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group4 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xFE];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        return match ($modRegRM->digit()) {
            0x0 => $this->inc($runtime, $reader, $modRegRM),
            0x1 => $this->dec($runtime, $reader, $modRegRM),
            default => throw new ExecutionException(sprintf('Group4 digit 0x%X not implemented', $modRegRM->digit())),
        };
    }

    protected function inc(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $value = $this->readRm8($runtime, $reader, $modRegRM);
        $result = ($value + 1) & 0xFF;
        $this->writeRm8($runtime, $reader, $modRegRM, $result);
        $runtime->memoryAccessor()->updateFlags($result, 8); // CF unaffected in INC
        return ExecutionStatus::SUCCESS;
    }

    protected function dec(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $value = $this->readRm8($runtime, $reader, $modRegRM);
        $result = ($value - 1) & 0xFF;
        $this->writeRm8($runtime, $reader, $modRegRM, $result);
        $runtime->memoryAccessor()->updateFlags($result, 8);
        return ExecutionStatus::SUCCESS;
    }
}
