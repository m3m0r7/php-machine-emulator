<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86_64;

use PHPMachineEmulator\Instruction\Traits\InstructionBaseTrait;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Instructable64 trait for x86_64 instructions.
 *
 * This trait extends the base instruction functionality with 64-bit support:
 * - 64-bit register access (RAX-R15)
 * - RIP-relative addressing
 * - REX prefix handling
 * - 64-bit operand size
 *
 * Use this trait for instructions that need 64-bit mode support.
 */
trait Instructable64
{
    use InstructionBaseTrait {
        readRegisterBySize as private baseReadRegisterBySize;
        writeRegisterBySize as private baseWriteRegisterBySize;
        effectiveAddressInfo as private baseEffectiveAddressInfo;
        read8BitRegister as private baseRead8BitRegister;
        write8BitRegister as private baseWrite8BitRegister;
    }

    /**
     * Read value from a register by size with 64-bit support.
     * Handles REX prefix for extended registers.
     */
    protected function readRegisterBySize(RuntimeInterface $runtime, int $register, int $size): int
    {
        $cpu = $runtime->context()->cpu();

        // In 64-bit mode with REX, handle extended registers
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            if ($size === 64) {
                $regCode = $cpu->rexB() ? ($register | 0b1000) : $register;
                $regType = $this->getRegisterType64($regCode);
                return $runtime->memoryAccessor()->fetch($regType)->asBytesBySize(64);
            }

            if ($size === 8 && $cpu->hasRex()) {
                return $this->read8BitRegister64($runtime, $register, $cpu->hasRex(), $cpu->rexB());
            }
        }

        return $this->baseReadRegisterBySize($runtime, $register, $size);
    }

    /**
     * Write value to a register by size with 64-bit support.
     * In 64-bit mode, 32-bit writes zero-extend to 64 bits.
     */
    protected function writeRegisterBySize(RuntimeInterface $runtime, int $register, int $value, int $size): void
    {
        $cpu = $runtime->context()->cpu();

        // In 64-bit mode with REX, handle extended registers
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            if ($size === 64) {
                $regCode = $cpu->rexB() ? ($register | 0b1000) : $register;
                $regType = $this->getRegisterType64($regCode);
                $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($regType, $value, 64);
                return;
            }

            // 32-bit writes in 64-bit mode zero-extend to 64-bit
            if ($size === 32) {
                $regCode = $cpu->rexB() ? ($register | 0b1000) : $register;
                $regType = $this->getRegisterType64($regCode);
                // Zero-extend: clear upper 32 bits
                $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($regType, $value & 0xFFFFFFFF, 64);
                return;
            }

            if ($size === 8 && $cpu->hasRex()) {
                $this->write8BitRegister64($runtime, $register, $value, $cpu->hasRex(), $cpu->rexB());
                return;
            }
        }

        $this->baseWriteRegisterBySize($runtime, $register, $value, $size);
    }

    /**
     * Read 8-bit register with 64-bit mode support.
     */
    protected function read8BitRegister(RuntimeInterface $runtime, int $register): int
    {
        $cpu = $runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
            return $this->read8BitRegister64($runtime, $register, true, $cpu->rexB());
        }

        return $this->baseRead8BitRegister($runtime, $register);
    }

    /**
     * Write 8-bit register with 64-bit mode support.
     */
    protected function write8BitRegister(RuntimeInterface $runtime, int $register, int $value, bool $updateFlags = true): void
    {
        $cpu = $runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
            $this->write8BitRegister64($runtime, $register, $value, true, $cpu->rexB(), $updateFlags);
            return;
        }

        $this->baseWrite8BitRegister($runtime, $register, $value, $updateFlags);
    }

    /**
     * Get effective address info with 64-bit support.
     */
    protected function effectiveAddressInfo(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): array
    {
        $cpu = $runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $addrSize = $cpu->addressSize();
            if ($addrSize === 64) {
                return $this->effectiveAddressAndSegment64($runtime, $reader, $modRegRM);
            }
        }

        return $this->baseEffectiveAddressInfo($runtime, $reader, $modRegRM);
    }

    /**
     * Get RegisterType for 64-bit register code.
     */
    private function getRegisterType64(int $code): RegisterType
    {
        return match ($code) {
            0 => RegisterType::EAX,  // RAX
            1 => RegisterType::ECX,  // RCX
            2 => RegisterType::EDX,  // RDX
            3 => RegisterType::EBX,  // RBX
            4 => RegisterType::ESP,  // RSP
            5 => RegisterType::EBP,  // RBP
            6 => RegisterType::ESI,  // RSI
            7 => RegisterType::EDI,  // RDI
            8 => RegisterType::R8,
            9 => RegisterType::R9,
            10 => RegisterType::R10,
            11 => RegisterType::R11,
            12 => RegisterType::R12,
            13 => RegisterType::R13,
            14 => RegisterType::R14,
            15 => RegisterType::R15,
            default => RegisterType::EAX,
        };
    }

    /**
     * Read from reg field in ModR/M with REX.R extension.
     */
    protected function readRegFromModRM(RuntimeInterface $runtime, ModRegRMInterface $modRegRM, int $size): int
    {
        $cpu = $runtime->context()->cpu();
        $regCode = $modRegRM->register();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexR()) {
            $regCode |= 0b1000;
        }

        if ($size === 64) {
            $regType = $this->getRegisterType64($regCode);
            return $runtime->memoryAccessor()->fetch($regType)->asBytesBySize(64);
        }

        return $this->readRegisterBySize($runtime, $regCode, $size);
    }

    /**
     * Write to reg field in ModR/M with REX.R extension.
     */
    protected function writeRegFromModRM(RuntimeInterface $runtime, ModRegRMInterface $modRegRM, int $value, int $size): void
    {
        $cpu = $runtime->context()->cpu();
        $regCode = $modRegRM->register();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexR()) {
            $regCode |= 0b1000;
        }

        if ($size === 64) {
            $regType = $this->getRegisterType64($regCode);
            $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($regType, $value, 64);
            return;
        }

        // In 64-bit mode, 32-bit writes zero-extend
        if ($size === 32 && $cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $regType = $this->getRegisterType64($regCode);
            $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($regType, $value & 0xFFFFFFFF, 64);
            return;
        }

        $this->writeRegisterBySize($runtime, $regCode, $value, $size);
    }

    /**
     * Read R/M operand with REX.B extension for register mode.
     */
    protected function readRm64(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): int
    {
        $cpu = $runtime->context()->cpu();

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $rmCode = $modRegRM->registerOrMemoryAddress();
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB()) {
                $rmCode |= 0b1000;
            }

            if ($size === 64) {
                $regType = $this->getRegisterType64($rmCode);
                return $runtime->memoryAccessor()->fetch($regType)->asBytesBySize(64);
            }

            return $this->readRegisterBySize($runtime, $rmCode, $size);
        }

        // Memory operand
        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        return match ($size) {
            8 => $this->readMemory8($runtime, $address),
            16 => $this->readMemory16($runtime, $address),
            32 => $this->readMemory32($runtime, $address),
            64 => $this->readMemory64($runtime, $address),
            default => $this->readMemory32($runtime, $address),
        };
    }

    /**
     * Write R/M operand with REX.B extension for register mode.
     */
    protected function writeRm64(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $value, int $size): void
    {
        $cpu = $runtime->context()->cpu();

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $rmCode = $modRegRM->registerOrMemoryAddress();
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB()) {
                $rmCode |= 0b1000;
            }

            if ($size === 64) {
                $regType = $this->getRegisterType64($rmCode);
                $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($regType, $value, 64);
                return;
            }

            // In 64-bit mode, 32-bit writes zero-extend
            if ($size === 32 && $cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
                $regType = $this->getRegisterType64($rmCode);
                $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($regType, $value & 0xFFFFFFFF, 64);
                return;
            }

            $this->writeRegisterBySize($runtime, $rmCode, $value, $size);
            return;
        }

        // Memory operand
        $linearAddress = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        match ($size) {
            8 => $this->writeMemory8($runtime, $linearAddress, $value),
            16 => $this->writeMemory16($runtime, $linearAddress, $value),
            32 => $this->writeMemory32($runtime, $linearAddress, $value),
            64 => $this->writeMemory64($runtime, $linearAddress, $value),
            default => $this->writeMemory64($runtime, $linearAddress, $value),
        };
    }
}
