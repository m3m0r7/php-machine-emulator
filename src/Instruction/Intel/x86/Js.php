<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Js implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x78];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->memory()
            ->signedByte();

        $pos = $runtime
            ->memory()
            ->offset();

        $sf = $runtime->memoryAccessor()->shouldSignFlag();
        $target = $pos + $operand;
        $runtime->option()->logger()->debug(sprintf('JS: pos=0x%04X operand=0x%02X target=0x%04X SF=%s taken=%s', $pos, $operand & 0xFF, $target, $sf ? '1' : '0', $sf ? 'YES' : 'NO'));

        if ($runtime->option()->shouldChangeOffset() && $sf) {
            $runtime
                ->memory()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
