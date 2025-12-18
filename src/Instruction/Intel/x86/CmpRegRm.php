<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class CmpRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x38, 0x39, 0x3A, 0x3B]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $isByte = in_array($opcode, [0x38, 0x3A], true);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $destIsRm = in_array($opcode, [0x38, 0x39], true);

        $src = $isByte
            ? ($destIsRm
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode())
                : $this->readRm8($runtime, $memory, $modRegRM))
            : ($destIsRm
                ? $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize)
                : $this->readRm($runtime, $memory, $modRegRM, $opSize));

        if ($isByte) {
            $dest = $destIsRm
                ? $this->readRm8($runtime, $memory, $modRegRM)
                : $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());
            $calc = $dest - $src;
            $maskedResult = $calc & 0xFF;
            $af = (($dest & 0x0F) < ($src & 0x0F));
            // OF for CMP (same as SUB): set if signs of operands differ and result sign equals subtrahend sign
            $signA = ($dest >> 7) & 1;
            $signB = ($src >> 7) & 1;
            $signR = ($maskedResult >> 7) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, 8)
                ->setCarryFlag($calc < 0)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
            $runtime->option()->logger()->debug(sprintf('CMP r/m8, r8: dest=0x%02X src=0x%02X ZF=%d', $dest, $src, $dest === $src ? 1 : 0));
        } else {
            $dest = $destIsRm
                ? $this->readRm($runtime, $memory, $modRegRM, $opSize)
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);

            // For unsigned comparison, dest < src means borrow (CF=1)
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signBit = $opSize === 32 ? 31 : 15;
            $destU = $dest & $mask;
            $srcU = $src & $mask;
            $calc = $destU - $srcU;
            $maskedResult = $calc & $mask;
            $cf = $calc < 0;
            $af = (($destU & 0x0F) < ($srcU & 0x0F));

            // OF for CMP (same as SUB): set if signs of operands differ and result sign equals subtrahend sign
            $signA = ($destU >> $signBit) & 1;
            $signB = ($srcU >> $signBit) & 1;
            $signR = ($maskedResult >> $signBit) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);
            $runtime->memoryAccessor()
                ->updateFlags($maskedResult, $opSize)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        }

        return ExecutionStatus::SUCCESS;
    }
}
