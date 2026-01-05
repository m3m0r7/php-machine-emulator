<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group4 implements InstructionInterface
{
    use Instructable;
    use GroupIncDec;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xFE]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        return match ($modRegRM->digit()) {
            0x0 => $this->inc($runtime, $memory, $modRegRM),
            0x1 => $this->dec($runtime, $memory, $modRegRM),
            default => throw new FaultException(0x06, null, sprintf('UD: Group4 digit 0x%X', $modRegRM->digit())),
        };
    }

    protected function inc(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $old = null;
        $result = null;
        $status = $this->incRmBySize($runtime, $memory, $modRegRM, 8, $old, $result);
        if ($old !== null && $result !== null) {
            $runtime->option()->logger()->debug(sprintf(
                'INC r/m8: %d -> %d (rm=%d)',
                $old,
                $result,
                $modRegRM->registerOrMemoryAddress()
            ));
        }
        return $status;
    }

    protected function dec(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        return $this->decRmBySize($runtime, $memory, $modRegRM, 8);
    }
}
