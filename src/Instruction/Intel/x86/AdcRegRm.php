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
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x10, 0x12], true);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $destIsRm = in_array($opcode, [0x10, 0x11], true);
        $carry = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        $src = $isByte
            ? ($destIsRm
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode())
                : $this->readRm8($runtime, $reader, $modRegRM))
            : ($destIsRm
                ? $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize)
                : $this->readRm($runtime, $reader, $modRegRM, $opSize));

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
                ? $this->readRm($runtime, $reader, $modRegRM, $opSize)
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);
            $result = $dest + $src + $carry;
            if ($destIsRm) {
                $this->writeRm($runtime, $reader, $modRegRM, $result, $opSize);
            } else {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $result, $opSize);
            }
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $runtime->memoryAccessor()->setCarryFlag($result > $mask)->updateFlags($result, $opSize);
        }

        return ExecutionStatus::SUCCESS;
    }
}
