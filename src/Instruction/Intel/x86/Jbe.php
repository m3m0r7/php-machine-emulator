<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jbe implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x76]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $operand = $runtime
            ->memory()
            ->signedByte();

        $pos = $runtime
            ->memory()
            ->offset();

        $cf = $runtime->memoryAccessor()->shouldCarryFlag();
        $zf = $runtime->memoryAccessor()->shouldZeroFlag();
        $taken = $cf || $zf;
        $target = $pos + $operand;
        $runtime->option()->logger()->debug(sprintf('JBE: pos=0x%04X operand=0x%02X target=0x%04X CF=%s ZF=%s taken=%s', $pos, $operand & 0xFF, $target, $cf ? '1' : '0', $zf ? '1' : '0', $taken ? 'YES' : 'NO'));

        if ($runtime->option()->shouldChangeOffset() && $taken) {
            $runtime
                ->memory()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
