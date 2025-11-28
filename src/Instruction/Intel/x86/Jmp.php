<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

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
        return [0xE9];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());

        $relOffset = $runtime->context()->cpu()->operandSize() === 32
            ? $enhancedStreamReader->signedDword()
            : $enhancedStreamReader->signedShort();

        $pos = $runtime
            ->streamReader()
            ->offset();

        // Check if we're in memory mode (executing code loaded into memory via INT 13h)
        $inMemoryMode = false;
        if ($runtime->streamReader() instanceof \PHPMachineEmulator\Stream\ISO\ISOStream) {
            $inMemoryMode = $runtime->streamReader()->isMemoryMode();
        }

        if ($inMemoryMode || $runtime->context()->cpu()->isProtectedMode()) {
            // In memory mode or protected mode, use linear addresses directly
            // pos is already a linear address, relOffset is the signed displacement
            $target = $pos + $relOffset;
            $runtime->option()->logger()->debug(sprintf('JMP near (memory mode): pos=0x%05X + rel=0x%04X = target=0x%05X', $pos, $relOffset & 0xFFFF, $target));

            if ($runtime->option()->shouldChangeOffset()) {
                $runtime->streamReader()->setOffset($target);
            }
            return ExecutionStatus::SUCCESS;
        }

        // Real mode with boot sector: add origin to convert to logical address
        $origin = $runtime->addressMap()->getOrigin();
        $target = $pos + $relOffset + $origin;
        $streamTarget = $target - $origin;

        // Debug JMP near
        $runtime->option()->logger()->debug(sprintf('JMP near: pos=0x%04X + rel=0x%04X + origin=0x%04X = target=0x%04X (streamTarget=0x%04X)', $pos, $relOffset & 0xFFFF, $origin, $target, $streamTarget));

        if (!$runtime->option()->shouldChangeOffset()) {
            return ExecutionStatus::SUCCESS;
        }

        // In real mode for ISO boot, use stream-relative offset directly
        $runtime
            ->streamReader()
            ->setOffset($streamTarget);

        return ExecutionStatus::SUCCESS;
    }
}
