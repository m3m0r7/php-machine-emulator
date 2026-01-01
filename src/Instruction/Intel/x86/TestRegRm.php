<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class TestRegRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x84, 0x85]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $isByte = $opcode === 0x84;
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();

        if ($isByte) {
            $left = $this->readRm8($runtime, $memory, $modRegRM);
            $right = $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());
            $result = $left & $right;
        } else {
            $left = $this->readRm($runtime, $memory, $modRegRM, $opSize);
            $right = $this->readRegisterBySize($runtime, $modRegRM->registerOrOPCode(), $opSize);
            if ($opSize === 64) {
                $ma = $runtime->memoryAccessor();
                $leftU = $left instanceof UInt64 ? $left : UInt64::of($left);
                $rightU = UInt64::of($right);
                $resultU = $leftU->and($rightU);

                $ma->setZeroFlag($resultU->isZero());
                $ma->setSignFlag($resultU->isNegativeSigned());
                $lowByte = $resultU->low32() & 0xFF;
                $ones = 0;
                for ($i = 0; $i < 8; $i++) {
                    $ones += ($lowByte >> $i) & 1;
                }
                $ma->setParityFlag(($ones % 2) === 0);
                $ma->setCarryFlag(false);
                $ma->setOverflowFlag(false);
                $ma->setAuxiliaryCarryFlag(false);

                return ExecutionStatus::SUCCESS;
            }

            $result = ($left & $right) & ($opSize === 32 ? 0xFFFFFFFF : 0xFFFF);
        }

        $runtime->memoryAccessor()
            ->updateFlags($result, $isByte ? 8 : $opSize)
            ->setCarryFlag(false)
            ->setOverflowFlag(false)
            ->setAuxiliaryCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }
}
