<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
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

        $cpu = $runtime->context()->cpu();

        // In 64-bit mode, REX.R extends the control register number (e.g., CR8).
        $cr = ($modrm->registerOrOPCode() & 0b111) |
            (($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexR()) ? 0b1000 : 0);

        // MOV to/from control registers always uses r32 (or r64 in long mode),
        // independent of the current operand-size attribute.
        $size = ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) ? 64 : 32;
        $gpr = Register::findGprByCode(
            $modrm->registerOrMemoryAddress(),
            $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB(),
        );
        $val = $runtime->memoryAccessor()->fetch($gpr)->asBytesBySize($size);
        $wasProtected = $runtime->context()->cpu()->isProtectedMode();

        if ($cr === 0) {
            $val |= 0x22; // MP + NE set so kernel assumes FPU present
        }

        $runtime->option()->logger()->debug(
            sprintf(
                'MOV to CR%d: value=0x%016X (size=%d)',
                $cr,
                $val,
                $size,
            )
        );

        $runtime->memoryAccessor()->writeControlRegister($cr, $val);

        if ($cr === 0) {
            $runtime->context()->cpu()->setProtectedMode((bool) ($val & 0x1));
            $runtime->context()->cpu()->setPagingEnabled((bool) ($val & 0x80000000));
            if ($wasProtected && !$runtime->context()->cpu()->isProtectedMode()) {
                $this->cacheCurrentSegmentDescriptors($runtime);
            }
        }
        if ($cr === 3 && $runtime->context()->cpu()->isPagingEnabled()) {
            $runtime->context()->cpu()->setPagingEnabled(true);
        }
        if ($cr === 4 && $runtime->context()->cpu()->isPagingEnabled()) {
            $runtime->context()->cpu()->setPagingEnabled(true);
        }

        // IA-32e activation depends on CR0/CR4/EFER; keep CPUContext in sync.
        $this->updateIa32eMode($runtime);

        return ExecutionStatus::SUCCESS;
    }
}
