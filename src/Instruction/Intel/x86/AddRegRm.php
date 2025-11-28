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
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $destIsRm = in_array($opcode, [0x00, 0x01], true);

        // Cache effective address to avoid reading displacement twice
        $rmAddress = null;
        if ($destIsRm && $modRegRM->mode() !== 0b11) {
            // Pre-compute r/m address so we don't re-read displacement
            $rmAddress = $this->translateLinear($runtime, $this->rmLinearAddress($runtime, $reader, $modRegRM), true);
        }

        $src = $isByte
            ? ($destIsRm
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode())
                : $this->readRm8($runtime, $reader, $modRegRM))
            : ($destIsRm
                ? $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize)
                : $this->readRm($runtime, $reader, $modRegRM, $opSize));

        if ($isByte) {
            $dest = $destIsRm
                ? ($rmAddress !== null ? $this->readMemory8($runtime, $rmAddress) : $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress()))
                : $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());
            $result = $dest + $src;
            if ($destIsRm) {
                if ($rmAddress !== null) {
                    $runtime->memoryAccessor()->allocate($rmAddress, 1, safe: false);
                    $runtime->memoryAccessor()->writeBySize($rmAddress, $result, 8);
                } else {
                    $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
                }
            } else {
                $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $result);
            }
            $runtime->memoryAccessor()->setCarryFlag($result > 0xFF)->updateFlags($result, 8);
        } else {
            $dest = $destIsRm
                ? ($rmAddress !== null
                    ? ($opSize === 32 ? $this->readMemory32($runtime, $rmAddress) : $this->readMemory16($runtime, $rmAddress))
                    : $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize))
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);
            $result = $dest + $src;
            if ($destIsRm) {
                if ($rmAddress !== null) {
                    $runtime->memoryAccessor()->allocate($rmAddress, $opSize === 32 ? 4 : 2, safe: false);
                    $runtime->memoryAccessor()->writeBySize($rmAddress, $result, $opSize);
                } else {
                    $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $opSize);
                }
            } else {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $result, $opSize);
            }
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $runtime->memoryAccessor()->setCarryFlag($result > $mask)->updateFlags($result, $opSize);
        }

        return ExecutionStatus::SUCCESS;
    }
}
