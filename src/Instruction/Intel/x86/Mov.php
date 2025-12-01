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
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $enhancedStreamReader
            ->byteAsModRegRM();

        $size = $runtime->context()->cpu()->operandSize();
        $regCode = $modRegRM->registerOrOPCode();
        $value = $this->readRegisterBySize($runtime, $regCode, $size);

        // Debug: trace MOV [ebp-offset], edx around distance calculation
        $ip = $runtime->memory()->offset();
        if ($ip >= 0x8CF8 && $ip <= 0x8D15 && $regCode === 2) { // EDX
            $runtime->option()->logger()->debug(sprintf(
                'MOV [rm], EDX: IP_after_modrm=0x%04X EDX_value=0x%08X',
                $ip, $value & 0xFFFFFFFF
            ));
        }

        $this->writeRm($runtime, $enhancedStreamReader, $modRegRM, $value, $size);

        // Debug: log memory writes
        $ip = $runtime->memory()->offset();
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
