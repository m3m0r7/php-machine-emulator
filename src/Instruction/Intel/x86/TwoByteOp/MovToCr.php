<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
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
        return $this->applyPrefixes([[0x0F, 0x22]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();

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
