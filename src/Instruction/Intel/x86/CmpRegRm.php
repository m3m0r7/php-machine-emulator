<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class CmpRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x38, 0x39, 0x3A, 0x3B];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        $isByte = in_array($opcode, [0x38, 0x3A], true);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $destIsRm = in_array($opcode, [0x38, 0x39], true);

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
            $runtime->memoryAccessor()->updateFlags($dest - $src, 8)->setCarryFlag($dest < $src);
            $runtime->option()->logger()->debug(sprintf('CMP r/m8, r8: dest=0x%02X src=0x%02X ZF=%d', $dest, $src, $dest === $src ? 1 : 0));
        } else {
            $dest = $destIsRm
                ? $this->readRm($runtime, $reader, $modRegRM, $opSize)
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);

            // For unsigned comparison, dest < src means borrow (CF=1)
            $mask = (1 << $opSize) - 1;
            $destU = $dest & $mask;
            $srcU = $src & $mask;
            $cf = $destU < $srcU;

            // Debug: Log CMP for LZMA direct bits decoding area
            $ip = $runtime->memory()->offset();
            if ($ip >= 0x8CD0 && $ip <= 0x8CE0) {
                $runtime->option()->logger()->debug(sprintf(
                    'CMP direct bits: IP=0x%04X dest=0x%08X src=0x%08X CF=%d',
                    $ip, $destU, $srcU, $cf ? 1 : 0
                ));
            }

            $runtime->memoryAccessor()->updateFlags($dest - $src, $opSize)->setCarryFlag($cf);
        }

        return ExecutionStatus::SUCCESS;
    }
}
