<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Call implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xE8]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $cpu = $runtime->context()->cpu();

        // In 64-bit mode, CALL rel32 always uses a 32-bit signed displacement and pushes a 64-bit RIP.
        // Operand-size override (0x66) is ignored for this instruction in 64-bit mode.
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $rel32 = $memory->signedDword();
            $nextRip = $runtime->memory()->offset();

            $runtime->memoryAccessor()->push(RegisterType::ESP, $nextRip, 64);

            if ($runtime->option()->shouldChangeOffset()) {
                $runtime->memory()->setOffset($nextRip + $rel32);
            }

            return ExecutionStatus::SUCCESS;
        }

        $opSize = $runtime->context()->cpu()->operandSize();
        $offset = $opSize === 32
            ? $memory->signedDword()
            : $memory->signedShort();

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        // After reading the displacement, the stream offset now points to the next instruction.
        $nextLinear = $runtime->memory()->offset();
        $cs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();

        // Calculate return offset and target using proper segment base resolution
        // This handles both real mode (cs << 4) and protected mode (descriptor base)
        $returnOffset = $this->codeOffsetFromLinear($runtime, $cs, $nextLinear, $opSize);
        $targetOffset = ($returnOffset + $offset) & $mask;
        $targetLinear = $this->linearCodeAddress($runtime, $cs, $targetOffset, $opSize);
        $returnToPush = $returnOffset;

        // Push return address onto stack.
        $espBefore = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize($opSize);
        $runtime
            ->memoryAccessor()
            ->push(RegisterType::ESP, $returnToPush, $opSize);

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime
                ->memory()
                ->setOffset($targetLinear);
        }

        return ExecutionStatus::SUCCESS;
    }
}
