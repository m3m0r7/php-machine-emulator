<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PushImm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x68, 0x6A]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $size = $runtime->context()->cpu()->operandSize();

        $value = $opcode === 0x68
            ? ($size === 32
                ? (($memory->short()) | ($memory->short() << 16)) // basic dword read
                : $memory->short())
            : $memory->signedByte();

        $runtime
            ->memoryAccessor()
            ->push(RegisterType::ESP, $value, $size);

        return ExecutionStatus::SUCCESS;
    }
}
