<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jmp implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xE9]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());

        $relOffset = $runtime->context()->cpu()->operandSize() === 32
            ? $enhancedStreamReader->signedDword()
            : $enhancedStreamReader->signedShort();

        $pos = $runtime
                ->memory()
            ->offset();

        if ($runtime->context()->cpu()->isProtectedMode()) {
            // In protected mode, use linear addresses directly
            // pos is already a linear address, relOffset is the signed displacement
            $target = $pos + $relOffset;
            $runtime->option()->logger()->debug(sprintf('JMP near (protected mode): pos=0x%05X + rel=0x%04X = target=0x%05X', $pos, $relOffset & 0xFFFF, $target));

            if ($runtime->option()->shouldChangeOffset()) {
                $runtime->memory()->setOffset($target);
            }
            return ExecutionStatus::SUCCESS;
        }

        // Real mode with unified memory: use linear addresses directly
        // pos is already a linear address (e.g., 0x7CCD), relOffset is signed displacement
        $target = $pos + $relOffset;

        // Debug JMP near
        $runtime->option()->logger()->debug(sprintf('JMP near: pos=0x%04X + rel=0x%04X = target=0x%04X', $pos, $relOffset & 0xFFFF, $target));

        if (!$runtime->option()->shouldChangeOffset()) {
            return ExecutionStatus::SUCCESS;
        }

        // In unified memory model, target is the linear address directly
        $runtime
                ->memory()
            ->setOffset($target);

        return ExecutionStatus::SUCCESS;
    }
}
