<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x7F];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        $zf = $runtime->memoryAccessor()->shouldZeroFlag();
        $sf = $runtime->memoryAccessor()->shouldSignFlag();
        $of = $runtime->memoryAccessor()->shouldOverflowFlag();

        // JG: jump if ZF=0 AND SF=OF
        if ($runtime->option()->shouldChangeOffset() && !$zf && ($sf === $of)) {
            $runtime
                ->streamReader()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }
}
