<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovRm16 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x8B]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $size = $runtime->context()->cpu()->operandSize();

        $value = $this->readRm($runtime, $memory, $modRegRM, $size);

        $regCode = $modRegRM->registerOrOPCode();

        $runtime
            ->memoryAccessor()
            ->writeBySize(
                $regCode,
                $value,
                $size,
            );

        return ExecutionStatus::SUCCESS;
    }
}
