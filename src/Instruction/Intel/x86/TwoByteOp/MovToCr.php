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
            // Debug: dump memory at 0xB1B0 before entering protected mode
            if (($val & 0x1) && !$runtime->context()->cpu()->isProtectedMode()) {
                $dumpAddr = 0xB1B0;
                $dumpData = [];
                for ($i = 0; $i < 32; $i++) {
                    $dumpData[] = sprintf('%02X', $runtime->memoryAccessor()->readRawByte($dumpAddr + $i) ?? 0);
                }
                $runtime->option()->logger()->debug(sprintf(
                    'Source RAW at 0x%05X BEFORE PM entry: %s',
                    $dumpAddr,
                    implode(' ', $dumpData)
                ));
            }

            // Debug: dump source and dest after bcopyxx when exiting PM
            if (!($val & 0x1) && $runtime->context()->cpu()->isProtectedMode()) {
                // Dump source (0xB1B0) - read raw memory
                $srcAddr = 0xB1B0;
                $srcData = [];
                for ($i = 0; $i < 32; $i++) {
                    $srcData[] = sprintf('%02X', $runtime->memoryAccessor()->readRawByte($srcAddr + $i) ?? 0);
                }
                $runtime->option()->logger()->debug(sprintf(
                    'Source RAW at 0x%05X: %s',
                    $srcAddr,
                    implode(' ', $srcData)
                ));

                // Dump dest (0x100000) - read raw memory
                $dstAddr = 0x100000;
                $dstData = [];
                for ($i = 0; $i < 32; $i++) {
                    $dstData[] = sprintf('%02X', $runtime->memoryAccessor()->readRawByte($dstAddr + $i) ?? 0);
                }
                $runtime->option()->logger()->debug(sprintf(
                    'Dest RAW at 0x%06X: %s',
                    $dstAddr,
                    implode(' ', $dstData)
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
