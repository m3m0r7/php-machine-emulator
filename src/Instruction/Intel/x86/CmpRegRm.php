<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class CmpRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x38, 0x39, 0x3A, 0x3B];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x38, 0x3A], true);
        $destIsRm = in_array($opcode, [0x38, 0x39], true);

        $src = $isByte
            ? ($destIsRm
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode())
                : $this->readRm8($runtime, $reader, $modRegRM))
            : ($destIsRm
                ? $runtime->memoryAccessor()->fetch($modRegRM->registerOrOPCode())->asByte()
                : $this->readRm16($runtime, $reader, $modRegRM));

        if ($isByte) {
            $dest = $destIsRm
                ? $this->readRm8($runtime, $reader, $modRegRM)
                : $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());
            $runtime->memoryAccessor()->updateFlags($dest - $src, 8)->setCarryFlag($dest < $src);
        } else {
            $dest = $destIsRm
                ? $this->readRm16($runtime, $reader, $modRegRM)
                : $runtime->memoryAccessor()->fetch($modRegRM->registerOrOPCode())->asByte();
            $runtime->memoryAccessor()->updateFlags($dest - $src, 16)->setCarryFlag($dest < $src);
        }

        return ExecutionStatus::SUCCESS;
    }
}
