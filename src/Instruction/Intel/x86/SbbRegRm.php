<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class SbbRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x18, 0x19, 0x1A, 0x1B];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x18, 0x1A], true);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $destIsRm = in_array($opcode, [0x18, 0x19], true);
        $borrow = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        // Cache effective address to avoid reading displacement twice
        $rmAddress = null;
        if ($destIsRm && $modRegRM->mode() !== 0b11) {
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
            $calc = $dest - $src - $borrow;
            $maskedResult = $calc & 0xFF;
            if ($destIsRm) {
                if ($rmAddress !== null) {
                    $this->writeMemory8($runtime, $rmAddress, $maskedResult);
                } else {
                    $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $maskedResult);
                }
            } else {
                $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $maskedResult);
            }
            // OF for SBB: set if signs of operands differ and result sign equals subtrahend sign
            $signA = ($dest >> 7) & 1;
            $signB = ($src >> 7) & 1;
            $signR = ($maskedResult >> 7) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, 8)
                ->setCarryFlag($calc < 0)
                ->setOverflowFlag($of);
        } else {
            $dest = $destIsRm
                ? ($rmAddress !== null
                    ? ($opSize === 32 ? $this->readMemory32($runtime, $rmAddress) : $this->readMemory16($runtime, $rmAddress))
                    : $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize))
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);
            $calc = $dest - $src - $borrow;
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signBit = $opSize === 32 ? 31 : 15;
            $maskedResult = $calc & $mask;
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
            // OF for SBB: set if signs of operands differ and result sign equals subtrahend sign
            $signA = ($dest >> $signBit) & 1;
            $signB = ($src >> $signBit) & 1;
            $signR = ($maskedResult >> $signBit) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, $opSize)
                ->setCarryFlag($calc < 0)
                ->setOverflowFlag($of);
        }

        return ExecutionStatus::SUCCESS;
    }
}
