<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class XorRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x30, 0x31, 0x32, 0x33];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x30, 0x32], true);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $destIsRm = in_array($opcode, [0x30, 0x31], true);

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
            $result = $dest ^ $src;
            if ($destIsRm) {
                $this->writeRm8($runtime, $reader, $modRegRM, $result);
            } else {
                $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $result);
            }
        } else {
            $dest = $destIsRm
                ? $this->readRm($runtime, $reader, $modRegRM, $opSize)
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);
            $result = $dest ^ $src;
            if ($destIsRm) {
                $this->writeRm($runtime, $reader, $modRegRM, $result, $opSize);
            } else {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $result, $opSize);
            }
        }

        $runtime->memoryAccessor()->setCarryFlag(false)->updateFlags($result, $isByte ? 8 : $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
