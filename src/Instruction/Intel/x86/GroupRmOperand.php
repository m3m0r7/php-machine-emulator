<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Util\UInt64;

trait GroupRmOperand
{
    /**
     * Resolve ModR/M operand location, consuming displacement if needed.
     *
     * @return array{0: bool, 1: int|null} [isRegister, address]
     */
    protected function resolveRmLocation(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): array
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $address = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);
        return [$isRegister, $address];
    }

    protected function readRm8FromLocation(
        RuntimeInterface $runtime,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        bool $isRegister,
        ?int $address,
    ): int {
        if ($isRegister) {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode()) {
                return $this->read8BitRegister64(
                    $runtime,
                    $modRegRM->registerOrMemoryAddress(),
                    $cpu->hasRex(),
                    $cpu->rexB(),
                );
            }
            return $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress());
        }

        $address ??= $this->rmLinearAddress($runtime, $memory, $modRegRM);
        return $this->readMemory8($runtime, $address);
    }

    protected function writeRm8FromLocation(
        RuntimeInterface $runtime,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        bool $isRegister,
        ?int $address,
        int $value,
    ): void {
        if ($isRegister) {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode()) {
                $this->write8BitRegister64(
                    $runtime,
                    $modRegRM->registerOrMemoryAddress(),
                    $value,
                    $cpu->hasRex(),
                    $cpu->rexB(),
                );
                return;
            }
            $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $value);
            return;
        }

        $address ??= $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $this->writeMemory8($runtime, $address, $value);
    }

    protected function readRmBySizeFromLocation(
        RuntimeInterface $runtime,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        bool $isRegister,
        ?int $address,
        int $size,
    ): int|UInt64 {
        if ($isRegister) {
            return $this->readRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), $size);
        }

        $address ??= $this->rmLinearAddress($runtime, $memory, $modRegRM);
        return match ($size) {
            8 => $this->readMemory8($runtime, $address),
            16 => $this->readMemory16($runtime, $address),
            32 => $this->readMemory32($runtime, $address),
            64 => $this->readMemory64($runtime, $address),
            default => $this->readMemory16($runtime, $address) & ((1 << $size) - 1),
        };
    }

    protected function writeRmBySizeFromLocation(
        RuntimeInterface $runtime,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        bool $isRegister,
        ?int $address,
        int|UInt64 $value,
        int $size,
    ): void {
        if ($isRegister) {
            $intValue = $value instanceof UInt64 ? $value->toInt() : $value;
            $this->writeRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), $intValue, $size);
            return;
        }

        $address ??= $this->rmLinearAddress($runtime, $memory, $modRegRM);
        match ($size) {
            8 => $this->writeMemory8($runtime, $address, $value instanceof UInt64 ? $value->low32() & 0xFF : $value),
            16 => $this->writeMemory16($runtime, $address, $value instanceof UInt64 ? $value->low32() & 0xFFFF : $value),
            32 => $this->writeMemory32($runtime, $address, $value instanceof UInt64 ? $value->low32() : $value),
            64 => $this->writeMemory64($runtime, $address, $value),
            default => $this->writeMemory32($runtime, $address, $value instanceof UInt64 ? $value->low32() : $value),
        };
    }

    protected function readRmScalarBySizeFromLocation(
        RuntimeInterface $runtime,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        bool $isRegister,
        ?int $address,
        int $size,
    ): int|UInt64 {
        if ($size === 8) {
            return $this->readRm8FromLocation($runtime, $memory, $modRegRM, $isRegister, $address);
        }

        return $this->readRmBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $size);
    }

    protected function writeRmScalarBySizeFromLocation(
        RuntimeInterface $runtime,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        bool $isRegister,
        ?int $address,
        int|UInt64 $value,
        int $size,
    ): void {
        if ($size === 8) {
            $this->writeRm8FromLocation(
                $runtime,
                $memory,
                $modRegRM,
                $isRegister,
                $address,
                $value instanceof UInt64 ? $value->low32() & 0xFF : $value,
            );
            return;
        }

        $this->writeRmBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $value, $size);
    }
}
