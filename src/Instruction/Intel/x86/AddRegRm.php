<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class AddRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x00, 0x01, 0x02, 0x03];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x00, 0x02], true);
        $opSize = $isByte ? 8 : $runtime->runtimeOption()->context()->operandSize();
        $destIsRm = in_array($opcode, [0x00, 0x01], true);

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
            $result = $dest + $src;
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
            $result = $dest + $src;
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
