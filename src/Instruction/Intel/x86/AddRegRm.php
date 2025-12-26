<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class AddRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x00, 0x01, 0x02, 0x03]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $isByte = in_array($opcode, [0x00, 0x02], true);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $destIsRm = in_array($opcode, [0x00, 0x01], true);

        // Cache effective address to avoid reading displacement twice
        $rmAddress = null;
        if ($destIsRm && $modRegRM->mode() !== 0b11) {
            // Pre-compute r/m address so we don't re-read displacement
            $rmAddress = $this->translateLinearWithMmio($runtime, $this->rmLinearAddress($runtime, $memory, $modRegRM), true);
        }

        $src = $isByte
            ? ($destIsRm
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode())
                : $this->readRm8($runtime, $memory, $modRegRM))
            : ($destIsRm
                ? $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize)
                : $this->readRm($runtime, $memory, $modRegRM, $opSize));

        if ($isByte) {
            $dest = $destIsRm
                ? ($rmAddress !== null ? $this->readMemory8($runtime, $rmAddress) : $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress()))
                : $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());
            $result = $dest + $src;
            $maskedResult = $result & 0xFF;
            if ($destIsRm) {
                if ($rmAddress !== null) {
                    $this->writeMemory8($runtime, $rmAddress, $maskedResult);
                } else {
                    $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $maskedResult);
                }
            } else {
                $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $maskedResult);
            }
            // OF for ADD: set if signs of operands are same but result sign differs
            $signA = ($dest >> 7) & 1;
            $signB = ($src >> 7) & 1;
            $signR = ($maskedResult >> 7) & 1;
            $of = ($signA === $signB) && ($signA !== $signR);
            // AF: carry from bit 3 to bit 4
            $af = (($dest & 0x0F) + ($src & 0x0F)) > 0x0F;
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, 8)
                ->setCarryFlag($result > 0xFF)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        } else {
            $dest = $destIsRm
                ? ($rmAddress !== null
                    ? ($opSize === 32 ? $this->readMemory32($runtime, $rmAddress) : $this->readMemory16($runtime, $rmAddress))
                    : $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize))
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);
            $result = $dest + $src;
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signBit = $opSize === 32 ? 31 : 15;
            $maskedResult = $result & $mask;
            if ($destIsRm) {
                if ($rmAddress !== null) {
                    if ($opSize === 32) {
                        $this->writeMemory32($runtime, $rmAddress, $maskedResult);
                    } else {
                        $this->writeMemory16($runtime, $rmAddress, $maskedResult);
                    }
                } else {
                    $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $maskedResult, $opSize);
                }
            } else {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $maskedResult, $opSize);
            }
            // OF for ADD: set if signs of operands are same but result sign differs
            $signA = ($dest >> $signBit) & 1;
            $signB = ($src >> $signBit) & 1;
            $signR = ($maskedResult >> $signBit) & 1;
            $of = ($signA === $signB) && ($signA !== $signR);
            // AF: carry from bit 3 to bit 4
            $af = (($dest & 0x0F) + ($src & 0x0F)) > 0x0F;
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, $opSize)
                ->setCarryFlag($result > $mask)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        }

        return ExecutionStatus::SUCCESS;
    }
}
