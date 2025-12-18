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
 * MOV r32, CRn (0x0F 0x20)
 * Move from control register to general-purpose register.
 */
class MovFromCr implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0x20]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();

        if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('MOV from CR requires register addressing');
        }

        $cpu = $runtime->context()->cpu();

        // In 64-bit mode, REX.R extends the control register number (e.g., CR8).
        $cr = ($modrm->registerOrOPCode() & 0b111) |
            (($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexR()) ? 0b1000 : 0);

        $val = $runtime->memoryAccessor()->readControlRegister($cr);
        // MOV to/from control registers always uses r32 (or r64 in long mode),
        // independent of the current operand-size attribute.
        $size = ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) ? 64 : 32;
        $gpr = Register::findGprByCode(
            $modrm->registerOrMemoryAddress(),
            $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB(),
        );
        $runtime->memoryAccessor()->writeBySize($gpr, $val, $size);

        return ExecutionStatus::SUCCESS;
    }
}
