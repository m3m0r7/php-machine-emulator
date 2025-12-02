<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovRm16 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x8B];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        $size = $runtime->context()->cpu()->operandSize();

        $value = $this->readRm($runtime, $enhancedStreamReader, $modRegRM, $size);

        // Debug FAT table read: MOV AX, [DS:SI] when SI around 0x200
        $regCode = $modRegRM->registerOrOPCode();
        $rm = $modRegRM->registerOrMemoryAddress();
        if ($regCode === 0 && $rm === 0b100) { // AX from [SI]
            $si = $runtime->memoryAccessor()->fetch(\PHPMachineEmulator\Instruction\RegisterType::ESI)->asByte();
            $ds = $runtime->memoryAccessor()->fetch(\PHPMachineEmulator\Instruction\RegisterType::DS)->asByte();
            if ($si >= 0x200 && $si < 0x400) {
                $linearAddr = ($ds << 4) + $si;
                $runtime->option()->logger()->debug(sprintf(
                    'MOV AX, [DS:SI]: DS=0x%04X SI=0x%04X linear=0x%05X value=0x%04X',
                    $ds, $si, $linearAddr, $value
                ));
            }
        }

        // Debug: log MOV to SI register (reg code 6 = SI)
        if ($regCode === 6) {
            $runtime->option()->logger()->debug(sprintf(
                'MOV SI, r/m16: value=0x%04X mode=%d rm=%d',
                $value,
                $modRegRM->mode(),
                $rm
            ));
        }


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
