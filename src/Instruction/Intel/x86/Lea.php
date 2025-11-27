<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Lea implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x8D];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('LEA does not support register-direct addressing');
        }

        [$address] = $this->effectiveAddressInfo($runtime, $reader, $modRegRM);

        $size = $runtime->context()->cpu()->operandSize();
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->writeBySize(
                $modRegRM->registerOrOPCode(),
                $address & $mask,
                $size,
            );

        return ExecutionStatus::SUCCESS;
    }
}
