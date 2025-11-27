<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class ImulImmediate implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x69, 0x6B];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->runtimeOption()->context()->operandSize();
        $isImm8 = $opcode === 0x6B;

        $imm = $isImm8
            ? $this->signExtend($reader->streamReader()->byte(), 8)
            : ($opSize === 32 ? $this->signExtend($reader->dword(), 32) : $this->signExtend($reader->short(), 16));

        $src = $this->readRm($runtime, $reader, $modrm, $opSize);
        $signedSrc = $this->signExtend($src, $opSize);
        $product = $signedSrc * $imm;
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
