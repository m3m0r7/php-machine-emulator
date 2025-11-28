<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Mov implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x89];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader
            ->byteAsModRegRM();

        $size = $runtime->context()->cpu()->operandSize();
        $value = $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $size);

        $this->writeRm($runtime, $enhancedStreamReader, $modRegRM, $value, $size);

        // Debug: log memory writes
        $ip = $runtime->streamReader()->offset();
        if ($ip >= 0x8315 && $ip <= 0x8318) {
            // Check what's at 0x7FFF0 directly
            $addr = 0x7FFF0;
            $b0 = $runtime->memoryAccessor()->readRawByte($addr) ?? -1;
            $b1 = $runtime->memoryAccessor()->readRawByte($addr + 1) ?? -1;
            $b2 = $runtime->memoryAccessor()->readRawByte($addr + 2) ?? -1;
            $b3 = $runtime->memoryAccessor()->readRawByte($addr + 3) ?? -1;
        }

        return ExecutionStatus::SUCCESS;
    }
}
