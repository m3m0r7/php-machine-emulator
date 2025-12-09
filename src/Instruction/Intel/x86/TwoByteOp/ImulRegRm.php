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
 * IMUL r16/32, r/m16/32 (0x0F 0xAF)
 * Two-operand signed multiply.
 */
class ImulRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0xAF]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $reader = new EnhanceStreamReader($runtime->memory());
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();

        $src = $this->readRm($runtime, $reader, $modrm, $opSize);
        $dst = $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize);

        $sSrc = $this->signExtend($src, $opSize);
        $sDst = $this->signExtend($dst, $opSize);
        $product = $sSrc * $sDst;

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = $product & $mask;

        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $result, $opSize);

        $min = -(1 << ($opSize - 1));
        $max = (1 << ($opSize - 1)) - 1;
        $overflow = $product < $min || $product > $max;
        $runtime->memoryAccessor()->setCarryFlag($overflow)->setOverflowFlag($overflow);

        return ExecutionStatus::SUCCESS;
    }
}
