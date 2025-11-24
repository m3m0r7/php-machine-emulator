<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class JmpFar implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xEA];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $offset = $reader->short();
        $segment = $reader->short();

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->streamReader()->setOffset($offset);
            $runtime->memoryAccessor()->write16Bit(RegisterType::CS, $segment);
        }

        return ExecutionStatus::SUCCESS;
    }
}
