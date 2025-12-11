<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Lea implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x8D]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('LEA does not support register-direct addressing');
        }

        [$address] = $this->effectiveAddressInfo($runtime, $memory, $modRegRM);

        $size = $runtime->context()->cpu()->operandSize();
        $cpu = $runtime->context()->cpu();

        // In real mode without A20 enabled, addresses are masked to 20 bits
        // In real mode with A20, full 32-bit is available
        // In protected mode, full 32-bit addressing is used
        if (!$cpu->isProtectedMode() && !$cpu->isA20Enabled()) {
            $mask = 0xFFFFF; // 20-bit mask for real mode
        } else {
            $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        }

        $regCode = $modRegRM->registerOrOPCode();

        $runtime
            ->memoryAccessor()
            ->writeBySize(
                $regCode,
                $address & $mask,
                $size,
            );

        return ExecutionStatus::SUCCESS;
    }
}
