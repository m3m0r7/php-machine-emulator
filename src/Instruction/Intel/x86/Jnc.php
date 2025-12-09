<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Jnc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x73]);
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

        // JNC: Jump if Not Carry (CF=0)
        $cf = $runtime->memoryAccessor()->shouldCarryFlag();
        $target = $pos + $operand;
        $runtime->option()->logger()->debug(sprintf('JNC: pos=0x%04X operand=0x%02X target=0x%04X CF=%s taken=%s', $pos, $operand & 0xFF, $target, $cf ? '1' : '0', !$cf ? 'YES' : 'NO'));

        if ($runtime->option()->shouldChangeOffset() && !$cf) {
            $runtime
                ->memory()
                ->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }
}
