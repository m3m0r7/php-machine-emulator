<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class AndRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x20, 0x21, 0x22, 0x23];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x20, 0x22], true);
        $opSize = $isByte ? 8 : $runtime->runtimeOption()->context()->operandSize();
        $destIsRm = in_array($opcode, [0x20, 0x21], true);

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
            $result = $dest & $src;
            if ($destIsRm) {
                $this->writeRm8($runtime, $reader, $modRegRM, $result);
            } else {
                $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $result);
            }
        } else {
            $dest = $destIsRm
                ? $this->readRm($runtime, $reader, $modRegRM, $opSize)
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);
            $result = $dest & $src;
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
