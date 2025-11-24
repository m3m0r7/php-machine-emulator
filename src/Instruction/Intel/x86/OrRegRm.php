<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class OrRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x08, 0x09, 0x0A, 0x0B];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x08, 0x0A], true);
        $destIsRm = in_array($opcode, [0x08, 0x09], true);

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
            $result = $dest | $src;
            if ($destIsRm) {
                $this->writeRm8($runtime, $reader, $modRegRM, $result);
            } else {
                $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $result);
            }
        } else {
            $dest = $destIsRm
                ? $this->readRm16($runtime, $reader, $modRegRM)
                : $runtime->memoryAccessor()->fetch($modRegRM->registerOrOPCode())->asByte();
            $result = $dest | $src;
            if ($destIsRm) {
                $this->writeRm16($runtime, $reader, $modRegRM, $result);
            } else {
                $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($modRegRM->registerOrOPCode(), $result);
            }
        }

        $runtime->memoryAccessor()->setCarryFlag(false)->updateFlags($result, $isByte ? 8 : 16);

        return ExecutionStatus::SUCCESS;
    }
}
