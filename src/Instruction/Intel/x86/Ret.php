<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Ret implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xC3, 0xC2, 0xCB, 0xCA];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $popBytes = ($opcode === 0xC2 || $opcode === 0xCA)
            ? $runtime->memory()->short()
            : 0;

        $size = $runtime->context()->cpu()->operandSize();

        $ma = $runtime->memoryAccessor();
        $espBefore = $ma->fetch(RegisterType::ESP)->asBytesBySize($size);
        $returnIp = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);

        $targetCs = $ma->fetch(RegisterType::CS)->asByte();
        $currentCpl = $runtime->context()->cpu()->cpl();
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $descriptor = null;
        $nextCpl = null;

        if ($opcode === 0xCB || $opcode === 0xCA) {
            // FAR ret: pop CS as well
            $targetCs = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
            if ($runtime->context()->cpu()->isProtectedMode()) {
                $descriptor = $this->resolveCodeDescriptor($runtime, $targetCs);
                $nextCpl = $this->computeCplForTransfer($runtime, $targetCs, $descriptor);
            }
            $newCpl = $targetCs & 0x3;

            if ($runtime->context()->cpu()->isProtectedMode() && $newCpl > $currentCpl) {
                // Returning to outer privilege: pop new ESP/SS from current stack before switching.
                $newEsp = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
                $newSs = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
                $ma->write16Bit(RegisterType::SS, $newSs & 0xFFFF);
                $ma->writeBySize(RegisterType::ESP, $newEsp & $mask, $size);
            }
        }

        if ($popBytes > 0) {
            $ma->writeBySize(RegisterType::ESP, ($ma->fetch(RegisterType::ESP)->asBytesBySize($size) + $popBytes) & $mask, $size);
        }

        if ($opcode === 0xCB || $opcode === 0xCA) {
            $this->writeCodeSegment($runtime, $targetCs, $nextCpl, $descriptor);
        }

        if ($runtime->option()->shouldChangeOffset()) {
            // Always use linearCodeAddress for both near and far returns
            // This properly handles protected mode by resolving segment base from descriptor
            $cs = ($opcode === 0xCB || $opcode === 0xCA) ? $targetCs : $ma->fetch(RegisterType::CS)->asByte();
            $linear = $this->linearCodeAddress($runtime, $cs, $returnIp, $size);
            $runtime->memory()->setOffset($linear);
        }

        return ExecutionStatus::SUCCESS;
    }
}
