<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PushImm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x68, 0x6A];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $size = $runtime->context()->cpu()->operandSize();

        $value = $opcode === 0x68
            ? ($size === 32
                ? (($reader->short()) | ($reader->short() << 16)) // basic dword read
                : $reader->short())
            : $reader->streamReader()->signedByte();

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->push(RegisterType::ESP, $value, $size);

        return ExecutionStatus::SUCCESS;
    }
}
