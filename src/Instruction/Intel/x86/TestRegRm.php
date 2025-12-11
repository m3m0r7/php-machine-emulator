<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class TestRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x84, 0x85]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $isByte = $opcode === 0x84;
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();

        if ($isByte) {
            $left = $this->readRm8($runtime, $memory, $modRegRM);
            $right = $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());
            $result = $left & $right;
        } else {
            $left = $this->readRm($runtime, $memory, $modRegRM, $opSize);
            $right = $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);
            $result = ($left & $right) & ($opSize === 32 ? 0xFFFFFFFF : 0xFFFF);
        }

        $runtime->memoryAccessor()->setCarryFlag(false)->setOverflowFlag(false)->updateFlags($result, $isByte ? 8 : $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
