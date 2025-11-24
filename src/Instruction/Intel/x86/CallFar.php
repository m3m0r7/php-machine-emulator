<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class CallFar implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x9A];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $offset = $reader->short();
        $segment = $reader->short();

        $pos = $runtime->streamReader()->offset();

        $size = $runtime->runtimeOption()->context()->operandSize();

        // push return CS:IP (address of next instruction)
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(), $size);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $pos, $size);

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->streamReader()->setOffset($offset);
            $runtime->memoryAccessor()->write16Bit(RegisterType::CS, $segment);
        }

        return ExecutionStatus::SUCCESS;
    }
}
