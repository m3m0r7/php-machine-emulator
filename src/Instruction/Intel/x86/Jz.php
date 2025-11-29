<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jz implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x74];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
                ->memory()
            ->signedByte();

        $pos = $runtime
                ->memory()
            ->offset();

        $zf = $runtime->memoryAccessor()->shouldZeroFlag();
        $target = $pos + $operand;
        $runtime->option()->logger()->debug(sprintf('JZ: pos=0x%04X operand=0x%02X target=0x%04X ZF=%s taken=%s', $pos, $operand & 0xFF, $target, $zf ? '1' : '0', $zf ? 'YES' : 'NO'));

        if ($runtime->option()->shouldChangeOffset() && $zf) {
            $runtime
                ->memory()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
