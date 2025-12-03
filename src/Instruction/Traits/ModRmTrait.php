<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for ModR/M byte handling operations.
 * Provides methods for reading and writing operands specified by ModR/M.
 * Used by both x86 and x86_64 instructions.
 */
trait ModRmTrait
{
    /**
     * Read operand from R/M field by size.
     */
    protected function readRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size);
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);

        $value = match ($size) {
            8 => $this->readMemory8($runtime, $address),
            16 => $this->readMemory16($runtime, $address),
            32 => $this->readMemory32($runtime, $address),
            64 => $this->readMemory64($runtime, $address),
            default => $this->readMemory16($runtime, $address) & ((1 << $size) - 1),
        };

        // Debug: log readRm for problem IP range
        $ip = $runtime->memory()->offset();
        if ($ip >= 0x1009C0 && $ip <= 0x1009E0) {
            $runtime->option()->logger()->debug(sprintf(
                'readRm: IP=0x%04X addr=0x%08X value=0x%08X size=%d mode=%d rm=%d',
                $ip,
                $address,
                $value & 0xFFFFFFFF,
                $size,
                $modRegRM->mode(),
                $modRegRM->registerOrMemoryAddress()
            ));
        }

        return $value;
    }

    /**
     * Write operand to R/M field by size.
     */
    protected function writeRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $value, int $size): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $value, $size);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        match ($size) {
            8 => $this->writeMemory8($runtime, $linearAddress, $value),
            16 => $this->writeMemory16($runtime, $linearAddress, $value),
            32 => $this->writeMemory32($runtime, $linearAddress, $value),
            64 => $this->writeMemory64($runtime, $linearAddress, $value),
            default => $this->writeMemory32($runtime, $linearAddress, $value),
        };
    }

    /**
     * Read 8-bit operand from R/M field.
     */
    protected function readRm8(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress());
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        return $this->readMemory8($runtime, $address);
    }

    /**
     * Write 8-bit operand to R/M field.
     */
    protected function writeRm8(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $value);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        // Debug: log writeRm8 memory address when writing printable chars
        if ($value >= 0x20 && $value < 0x7F) {
            $runtime->option()->logger()->debug(sprintf(
                'writeRm8: linear=0x%05X value=0x%02X (char=%s)',
                $linearAddress,
                $value,
                chr($value)
            ));
        }
        $this->writeMemory8($runtime, $linearAddress, $value);
    }

    /**
     * Read 16-bit operand from R/M field.
     */
    protected function readRm16(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $runtime->memoryAccessor()->fetch($modRegRM->registerOrMemoryAddress())->asByte();
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $value = $this->readMemory16($runtime, $address);

        // Debug: log readRm16 for problem IP range
        $ip = $runtime->memory()->offset();
        if ($ip >= 0x1009C0 && $ip <= 0x1009E0) {
            $runtime->option()->logger()->debug(sprintf(
                'readRm16: IP=0x%04X addr=0x%08X value=0x%04X mode=%d rm=%d',
                $ip,
                $address,
                $value & 0xFFFF,
                $modRegRM->mode(),
                $modRegRM->registerOrMemoryAddress()
            ));
        }

        return $value;
    }

    /**
     * Write 16-bit operand to R/M field.
     */
    protected function writeRm16(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $runtime->memoryAccessor()->write16Bit($modRegRM->registerOrMemoryAddress(), $value);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $this->writeMemory16($runtime, $linearAddress, $value);
    }

    /**
     * Read 32-bit operand from R/M field.
     */
    protected function readRm32(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $runtime->memoryAccessor()->fetch($modRegRM->registerOrMemoryAddress())->asBytesBySize(32);
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        return $this->readMemory32($runtime, $address);
    }

    /**
     * Write 32-bit operand to R/M field.
     */
    protected function writeRm32(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $runtime->memoryAccessor()->writeBySize($modRegRM->registerOrMemoryAddress(), $value, 32);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $this->writeMemory32($runtime, $linearAddress, $value);
    }

    /**
     * Read 64-bit operand from R/M field.
     */
    protected function readRm64(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $runtime->memoryAccessor()->fetch($modRegRM->registerOrMemoryAddress())->asBytesBySize(64);
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        return $this->readMemory64($runtime, $address);
    }

    /**
     * Write 64-bit operand to R/M field.
     */
    protected function writeRm64(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $runtime->memoryAccessor()->writeBySize($modRegRM->registerOrMemoryAddress(), $value, 64);
            return;
        }

        $linearAddress = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $this->writeMemory64($runtime, $linearAddress, $value);
    }

    // Abstract methods that must be implemented by using class/trait
    abstract protected function readRegisterBySize(RuntimeInterface $runtime, int|\PHPMachineEmulator\Instruction\RegisterType $register, int $size): int;
    abstract protected function writeRegisterBySize(RuntimeInterface $runtime, int|\PHPMachineEmulator\Instruction\RegisterType $register, int $value, int $size): void;
    abstract protected function read8BitRegister(RuntimeInterface $runtime, int $register): int;
    abstract protected function write8BitRegister(RuntimeInterface $runtime, int $register, int $value): void;
    abstract protected function rmLinearAddress(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, \PHPMachineEmulator\Instruction\RegisterType|null $segmentOverride = null): int;
    abstract protected function readMemory8(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory16(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory32(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory64(RuntimeInterface $runtime, int $address): int;
    abstract protected function writeMemory8(RuntimeInterface $runtime, int $address, int $value): void;
    abstract protected function writeMemory16(RuntimeInterface $runtime, int $address, int $value): void;
    abstract protected function writeMemory32(RuntimeInterface $runtime, int $address, int $value): void;
    abstract protected function writeMemory64(RuntimeInterface $runtime, int $address, int $value): void;
}
