<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class JmpShort implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xEB];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->signedByte();

        $pos = $runtime
            ->streamReader()
            ->offset();

        $target = $pos + $operand;
        $runtime->option()->logger()->debug(sprintf('JMP short: pos=0x%04X + operand=0x%02X = target=0x%04X', $pos, $operand & 0xFF, $target));

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime
                ->streamReader()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
