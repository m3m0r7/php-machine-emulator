<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Util\UInt64;

trait GroupIncDec
{
    use GroupRmOperand;

    protected function incRmBySize(
        RuntimeInterface $runtime,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        int $size,
        ?int &$oldValue = null,
        ?int &$resultValue = null,
    ): ExecutionStatus {
        $ma = $runtime->memoryAccessor();

        // Preserve CF - INC does not affect carry flag
        $savedCf = $ma->shouldCarryFlag();

        [$isRegister, $address] = $this->resolveRmLocation($runtime, $memory, $modRegRM);

        if ($size === 64) {
            $oldScalar = $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, 64);
            $oldU = $oldScalar instanceof UInt64 ? $oldScalar : UInt64::of($oldScalar);
            $resultU = $oldU->add(1);
            $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $resultU, 64);

            $oldValue = $oldU->toInt();
            $resultValue = $resultU->toInt();

            $ma->updateFlags($resultValue, 64);
            $ma->setAuxiliaryCarryFlag((($oldValue & 0x0F) + 1) > 0x0F);
            $ma->setOverflowFlag($resultValue === PHP_INT_MIN);
            $ma->setCarryFlag($savedCf);
            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->incDecMaskForSize($size);
        $oldValue = (int) $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $size);
        $oldValue &= $mask;
        $resultValue = ($oldValue + 1) & $mask;
        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $resultValue, $size);

        $ma->updateFlags($resultValue, $size);
        $ma->setAuxiliaryCarryFlag((($oldValue & 0x0F) + 1) > 0x0F);
        $ma->setCarryFlag($savedCf);
        $ma->setOverflowFlag($resultValue === (1 << ($size - 1)));

        return ExecutionStatus::SUCCESS;
    }

    protected function decRmBySize(
        RuntimeInterface $runtime,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        int $size,
        ?int &$oldValue = null,
        ?int &$resultValue = null,
    ): ExecutionStatus {
        $ma = $runtime->memoryAccessor();

        // Preserve CF - DEC does not affect carry flag
        $savedCf = $ma->shouldCarryFlag();

        [$isRegister, $address] = $this->resolveRmLocation($runtime, $memory, $modRegRM);

        if ($size === 64) {
            $oldScalar = $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, 64);
            $oldU = $oldScalar instanceof UInt64 ? $oldScalar : UInt64::of($oldScalar);
            $resultU = $oldU->sub(1);
            $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $resultU, 64);

            $oldValue = $oldU->toInt();
            $resultValue = $resultU->toInt();

            $ma->updateFlags($resultValue, 64);
            $ma->setAuxiliaryCarryFlag(($oldValue & 0x0F) === 0);
            $ma->setOverflowFlag($resultValue === PHP_INT_MAX);
            $ma->setCarryFlag($savedCf);
            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->incDecMaskForSize($size);
        $oldValue = (int) $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $size);
        $oldValue &= $mask;
        $resultValue = ($oldValue - 1) & $mask;
        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $resultValue, $size);

        $ma->updateFlags($resultValue, $size);
        $ma->setAuxiliaryCarryFlag(($oldValue & 0x0F) === 0);
        $ma->setCarryFlag($savedCf);
        $ma->setOverflowFlag($resultValue === ((1 << ($size - 1)) - 1));

        return ExecutionStatus::SUCCESS;
    }

    private function incDecMaskForSize(int $size): int
    {
        return match ($size) {
            8 => 0xFF,
            16 => 0xFFFF,
            default => 0xFFFFFFFF,
        };
    }
}
