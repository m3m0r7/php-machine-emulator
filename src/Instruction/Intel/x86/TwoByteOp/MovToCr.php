<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * MOV CRn, r32 (0x0F 0x22)
 * Move from general-purpose register to control register.
 */
class MovToCr implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [[0x0F, 0x22]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();

        if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('MOV to CR requires register addressing');
        }

        $cr = $modrm->registerOrOPCode() & 0b111;
        $size = $runtime->context()->cpu()->operandSize();
        $val = $runtime->memoryAccessor()->fetch($modrm->registerOrMemoryAddress())->asBytesBySize($size);

        if ($cr === 0) {
            $val |= 0x22; // MP + NE set so kernel assumes FPU present
        }

        $runtime->option()->logger()->debug(sprintf(
            'MOV to CR%d: value=0x%08X (size=%d)',
            $cr,
            $val & 0xFFFFFFFF,
            $size
        ));

        $runtime->memoryAccessor()->writeControlRegister($cr, $val);

        if ($cr === 0) {
            // Debug: dump memory at 0xB1D0-0xB1E0 before entering protected mode
            if (($val & 0x1) && !$runtime->context()->cpu()->isProtectedMode()) {
                $dumpAddr = 0xB1D0;
                $dumpData = [];
                for ($i = 0; $i < 16; $i++) {
                    $dumpData[] = sprintf('%02X', $this->readMemory8($runtime, $dumpAddr + $i));
                }
                $runtime->option()->logger()->debug(sprintf(
                    'Memory at 0x%05X before PM entry: %s',
                    $dumpAddr,
                    implode(' ', $dumpData)
                ));
            }

            $runtime->context()->cpu()->setProtectedMode((bool) ($val & 0x1));
            $runtime->context()->cpu()->setPagingEnabled((bool) ($val & 0x80000000));
        }
        if ($cr === 3 && $runtime->context()->cpu()->isPagingEnabled()) {
            $runtime->context()->cpu()->setPagingEnabled(true);
        }
        if ($cr === 4 && $runtime->context()->cpu()->isPagingEnabled()) {
            $runtime->context()->cpu()->setPagingEnabled(true);
        }

        return ExecutionStatus::SUCCESS;
    }
}
