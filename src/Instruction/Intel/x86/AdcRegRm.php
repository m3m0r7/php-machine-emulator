<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class AdcRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x10, 0x11, 0x12, 0x13];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x10, 0x12], true);
        $destIsRm = in_array($opcode, [0x10, 0x11], true);
        $carry = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

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
            $result = $dest + $src + $carry;
            if ($destIsRm) {
                $this->writeRm8($runtime, $reader, $modRegRM, $result);
            } else {
                $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($result > 0xFF)->updateFlags($result, 8);
        } else {
            $dest = $destIsRm
                ? $this->readRm16($runtime, $reader, $modRegRM)
                : $runtime->memoryAccessor()->fetch($modRegRM->registerOrOPCode())->asByte();
            $result = $dest + $src + $carry;
            if ($destIsRm) {
                $this->writeRm16($runtime, $reader, $modRegRM, $result);
            } else {
                $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($modRegRM->registerOrOPCode(), $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($result > 0xFFFF)->updateFlags($result, 16);
        }

        return ExecutionStatus::SUCCESS;
    }
}
