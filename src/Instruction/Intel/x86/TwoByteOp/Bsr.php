<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * BSR (0x0F 0xBD)
 * Bit scan reverse.
 */
class Bsr implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0xBD]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();

        $src = $this->readRm($runtime, $reader, $modrm, $opSize);

        if ($src === 0) {
            $runtime->memoryAccessor()->setZeroFlag(true);
            return ExecutionStatus::SUCCESS;
        }

        $runtime->memoryAccessor()->setZeroFlag(false);

        $index = $opSize - 1;
        while ($index >= 0 && ((($src >> $index) & 1) === 0)) {
            $index--;
        }

        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), max(0, $index), $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
