<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class TestRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x84, 0x85];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = $opcode === 0x84;

        if ($isByte) {
            $left = $this->readRm8($runtime, $reader, $modRegRM);
            $right = $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());
            $result = $left & $right;
        } else {
            $left = $this->readRm16($runtime, $reader, $modRegRM);
            $right = $runtime->memoryAccessor()->fetch($modRegRM->registerOrOPCode())->asByte();
            $result = $left & $right;
        }

        $runtime->memoryAccessor()->setCarryFlag(false)->updateFlags($result, $isByte ? 8 : 16);

        return ExecutionStatus::SUCCESS;
    }
}
