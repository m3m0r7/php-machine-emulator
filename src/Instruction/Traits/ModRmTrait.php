<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Util\UInt64;

/**
 * Trait for ModR/M byte handling operations.
 * Provides methods for reading and writing operands specified by ModR/M.
 * Used by both x86 and x86_64 instructions.
 */
trait ModRmTrait
{
    protected function rmGprRegisterType(RuntimeInterface $runtime, ModRegRMInterface $modRegRM): \PHPMachineEmulator\Instruction\RegisterType
    {
        $cpu = $runtime->context()->cpu();
        return Register::findGprByCode($modRegRM->registerOrMemoryAddress(), $cpu->rexB());
    }

    /**
     * Read operand from R/M field by size.
     * @return int|UInt64 Returns UInt64 for 64-bit reads, int otherwise
     */
    protected function readRm(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): int|UInt64
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $this->readRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), $size);
        }

        $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);

        $value = match ($size) {
            8 => $this->readMemory8($runtime, $address),
            16 => $this->readMemory16($runtime, $address),
            32 => $this->readMemory32($runtime, $address),
            64 => $this->readMemory64($runtime, $address),
            default => $this->readMemory16($runtime, $address) & ((1 << $size) - 1),
        };

        return $value;
    }

    /**
     * Write operand to R/M field by size.
     */
    protected function writeRm(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int|UInt64 $value, int $size): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $intValue = $value instanceof UInt64 ? $value->toInt() : $value;
            $this->writeRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), $intValue, $size);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        match ($size) {
            8 => $this->writeMemory8($runtime, $linearAddress, $value instanceof UInt64 ? $value->low32() & 0xFF : $value),
            16 => $this->writeMemory16($runtime, $linearAddress, $value instanceof UInt64 ? $value->low32() & 0xFFFF : $value),
            32 => $this->writeMemory32($runtime, $linearAddress, $value instanceof UInt64 ? $value->low32() : $value),
            64 => $this->writeMemory64($runtime, $linearAddress, $value),
            default => $this->writeMemory32($runtime, $linearAddress, $value instanceof UInt64 ? $value->low32() : $value),
        };
    }

    /**
     * Read 8-bit operand from R/M field.
     */
    protected function readRm8(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode()) {
                return $this->read8BitRegister64($runtime, $modRegRM->registerOrMemoryAddress(), $cpu->hasRex(), $cpu->rexB());
            }
            return $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress());
        }

        $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        return $this->readMemory8($runtime, $address);
    }

    /**
     * Write 8-bit operand to R/M field.
     */
    protected function writeRm8(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode()) {
                $this->write8BitRegister64($runtime, $modRegRM->registerOrMemoryAddress(), $value, $cpu->hasRex(), $cpu->rexB());
                return;
            }
            $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $value);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $this->writeMemory8($runtime, $linearAddress, $value);
    }

    /**
     * Write 8-bit operand to R/M field with debug logging.
     */
    protected function writeRm8WithDebug(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $value, bool $debug): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode()) {
                $this->write8BitRegister64($runtime, $modRegRM->registerOrMemoryAddress(), $value, $cpu->hasRex(), $cpu->rexB());
                return;
            }
            $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $value);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        if ($debug) {
            $runtime->option()->logger()->warning(sprintf(
                'writeRm8WithDebug: linearAddress=0x%05X value=0x%02X',
                $linearAddress,
                $value
            ));
        }
        $this->writeMemory8($runtime, $linearAddress, $value);
        if ($debug) {
            // Verify the write
            $readBack = $this->readMemory8($runtime, $linearAddress);
            $runtime->option()->logger()->warning(sprintf(
                'writeRm8WithDebug: readBack=0x%02X at 0x%05X',
                $readBack,
                $linearAddress
            ));
        }
    }

    /**
     * Read 16-bit operand from R/M field.
     */
    protected function readRm16(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $this->readRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), 16);
        }

        $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $value = $this->readMemory16($runtime, $address);

        return $value;
    }

    /**
     * Write 16-bit operand to R/M field.
     */
    protected function writeRm16(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $this->writeRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), $value, 16);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $this->writeMemory16($runtime, $linearAddress, $value);
    }

    /**
     * Read 32-bit operand from R/M field.
     */
    protected function readRm32(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $this->readRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), 32);
        }

        $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        return $this->readMemory32($runtime, $address);
    }

    /**
     * Write 32-bit operand to R/M field.
     */
    protected function writeRm32(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $this->writeRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), $value, 32);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $this->writeMemory32($runtime, $linearAddress, $value);
    }

    /**
     * Read 64-bit operand from R/M field.
     */
    protected function readRm64(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): UInt64
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $value = $this->readRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), 64);
            return UInt64::of($value);
        }

        $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        return $this->readMemory64($runtime, $address);
    }

    /**
     * Write 64-bit operand to R/M field.
     */
    protected function writeRm64(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, UInt64|int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $intValue = $value instanceof UInt64 ? $value->toInt() : $value;
            $this->writeRegisterBySize($runtime, $this->rmGprRegisterType($runtime, $modRegRM), $intValue, 64);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $this->writeMemory64($runtime, $linearAddress, $value);
    }

    // Abstract methods that must be implemented by using class/trait
    abstract protected function readRegisterBySize(RuntimeInterface $runtime, int|\PHPMachineEmulator\Instruction\RegisterType $register, int $size): int;
    abstract protected function writeRegisterBySize(RuntimeInterface $runtime, int|\PHPMachineEmulator\Instruction\RegisterType $register, int $value, int $size): void;
    abstract protected function read8BitRegister(RuntimeInterface $runtime, int $register): int;
    abstract protected function write8BitRegister(RuntimeInterface $runtime, int $register, int $value): void;
    abstract protected function rmLinearAddress(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, \PHPMachineEmulator\Instruction\RegisterType|null $segmentOverride = null): int;
    abstract protected function readMemory8(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory16(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory32(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory64(RuntimeInterface $runtime, int $address): UInt64;
    abstract protected function writeMemory8(RuntimeInterface $runtime, int $address, int $value): void;
    abstract protected function writeMemory16(RuntimeInterface $runtime, int $address, int $value): void;
    abstract protected function writeMemory32(RuntimeInterface $runtime, int $address, int $value): void;
    abstract protected function writeMemory64(RuntimeInterface $runtime, int $address, UInt64|int $value): void;
}
