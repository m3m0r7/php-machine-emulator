<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class SubRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x28, 0x29, 0x2A, 0x2B]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x28, 0x2A], true);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $destIsRm = in_array($opcode, [0x28, 0x29], true);

        // Cache effective address to avoid reading displacement twice
        $rmAddress = null;
        if ($destIsRm && $modRegRM->mode() !== 0b11) {
            // Pre-compute r/m address so we don't re-read displacement
            $rmAddress = $this->translateLinearWithMmio($runtime, $this->rmLinearAddress($runtime, $reader, $modRegRM), true);
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
            $calc = $dest - $src;
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
            // OF for SUB: set if signs of operands differ and result sign equals subtrahend sign
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
            $calc = $dest - $src;
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signBit = $opSize === 32 ? 31 : 15;
            $maskedResult = $calc & $mask;

            // Debug SUB for LZMA distance calculation
            $runtime->option()->logger()->debug(sprintf(
                'SUB r%d: dest=0x%X src=0x%X result=0x%X (destIsRm=%s mode=%d rmReg=%d rmAddr=%s)',
                $opSize, $dest & 0xFFFFFFFF, $src & 0xFFFFFFFF, $maskedResult,
                $destIsRm ? 'yes' : 'no',
                $modRegRM->mode(),
                $modRegRM->registerOrMemoryAddress(),
                $rmAddress !== null ? sprintf('0x%X', $rmAddress) : 'null'
            ));

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
            // OF for SUB: set if signs of operands differ and result sign equals subtrahend sign
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
