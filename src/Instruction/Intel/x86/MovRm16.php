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

        // Debug: log MOV to ESP register (reg code 4 = ESP)
        if ($regCode === 4) {
            $espBefore = $runtime->memoryAccessor()->fetch(\PHPMachineEmulator\Instruction\RegisterType::ESP)->asBytesBySize(32);
            $runtime->option()->logger()->debug(sprintf(
                'MOV ESP, r/m: before=0x%08X value=0x%08X size=%d mode=%d rm=%d',
                $espBefore,
                $value & 0xFFFFFFFF,
                $size,
                $modRegRM->mode(),
                $rm
            ));
        }

        // Debug: log MOV to EDI register (reg code 7 = EDI) in target function
        $ip = $runtime->memory()->offset();
        if ($regCode === 7 && $ip >= 0x1009AA && $ip <= 0x1009F0) {
            $ediBefore = $runtime->memoryAccessor()->fetch(\PHPMachineEmulator\Instruction\RegisterType::EDI)->asBytesBySize(32);
            $runtime->option()->logger()->debug(sprintf(
                'MOV EDI, r/m: IP=0x%04X before=0x%08X value=0x%08X size=%d mode=%d rm=%d',
                $ip,
                $ediBefore,
                $value & 0xFFFFFFFF,
                $size,
                $modRegRM->mode(),
                $rm
            ));
        }

        // Debug: log MOV to EDX register (reg code 2 = EDX) in target function
        if ($regCode === 2 && $ip >= 0x1009C0 && $ip <= 0x1009D0) {
            $edxBefore = $runtime->memoryAccessor()->fetch(\PHPMachineEmulator\Instruction\RegisterType::EDX)->asBytesBySize(32);
            $runtime->option()->logger()->debug(sprintf(
                'MOV EDX, r/m: IP=0x%04X before=0x%08X value=0x%08X size=%d mode=%d rm=%d',
                $ip,
                $edxBefore,
                $value & 0xFFFFFFFF,
                $size,
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
