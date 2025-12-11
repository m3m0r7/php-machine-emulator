<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86_64;

use PHPMachineEmulator\Instruction\Traits\InstructionBaseTrait;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Util\UInt64;

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
                $runtime->memoryAccessor()->writeBySize($regType, $value, 64);
                return;
            }

            // 32-bit writes in 64-bit mode zero-extend to 64-bit
            if ($size === 32) {
                $regCode = $cpu->rexB() ? ($register | 0b1000) : $register;
                $regType = $this->getRegisterType64($regCode);
                // Zero-extend: clear upper 32 bits
                $runtime->memoryAccessor()->writeBySize($regType, $value & 0xFFFFFFFF, 64);
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
    protected function write8BitRegister(RuntimeInterface $runtime, int $register, int $value): void
    {
        $cpu = $runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
            $this->write8BitRegister64($runtime, $register, $value, true, $cpu->rexB());
            return;
        }

        $this->baseWrite8BitRegister($runtime, $register, $value);
    }

    /**
     * Get effective address info with 64-bit support.
     */
    protected function effectiveAddressInfo(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): array
    {
        $cpu = $runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $addrSize = $cpu->addressSize();
            if ($addrSize === 64) {
                return $this->effectiveAddressAndSegment64($runtime, $memory, $modRegRM);
            }
        }

        return $this->baseEffectiveAddressInfo($runtime, $memory, $modRegRM);
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
            $runtime->memoryAccessor()->writeBySize($regType, $value, 64);
            return;
        }

        // In 64-bit mode, 32-bit writes zero-extend
        if ($size === 32 && $cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $regType = $this->getRegisterType64($regCode);
            $runtime->memoryAccessor()->writeBySize($regType, $value & 0xFFFFFFFF, 64);
            return;
        }

        $this->writeRegisterBySize($runtime, $regCode, $value, $size);
    }

    /**
     * Read R/M operand with REX.B extension for register mode.
     */
    protected function readRm64(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): int
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
        $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
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
    protected function writeRm64(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $value, int $size): void
    {
        $cpu = $runtime->context()->cpu();

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $rmCode = $modRegRM->registerOrMemoryAddress();
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB()) {
                $rmCode |= 0b1000;
            }

            if ($size === 64) {
                $regType = $this->getRegisterType64($rmCode);
                $runtime->memoryAccessor()->writeBySize($regType, $value, 64);
                return;
            }

            // In 64-bit mode, 32-bit writes zero-extend
            if ($size === 32 && $cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
                $regType = $this->getRegisterType64($rmCode);
                $runtime->memoryAccessor()->writeBySize($regType, $value & 0xFFFFFFFF, 64);
                return;
            }

            $this->writeRegisterBySize($runtime, $rmCode, $value, $size);
            return;
        }

        // Memory operand
        $linearAddress = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        match ($size) {
            8 => $this->writeMemory8($runtime, $linearAddress, $value),
            16 => $this->writeMemory16($runtime, $linearAddress, $value),
            32 => $this->writeMemory32($runtime, $linearAddress, $value),
            64 => $this->writeMemory64($runtime, $linearAddress, $value),
            default => $this->writeMemory64($runtime, $linearAddress, $value),
        };
    }

    /**
     * Calculate effective address with 64-bit addressing (including RIP-relative).
     */
    protected function effectiveAddressAndSegment64(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): array
    {
        $cpu = $runtime->context()->cpu();
        $mod = $modRegRM->mode();
        $rm = $modRegRM->registerOrMemoryAddress();

        // In 64-bit mode, segment overrides are generally ignored (except FS/GS)
        $segment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        // Apply REX.B to rm field
        if ($cpu->rexB()) {
            $rm |= 0b1000;
        }

        // mod == 11: register-to-register (no memory access)
        if ($mod === 0b11) {
            return [0, $segment];
        }

        // Check for SIB byte (rm == 4 in 64-bit mode still means SIB)
        $needsSib = ($rm & 0b111) === 0b100;

        if ($needsSib) {
            return $this->decodeSib64($runtime, $memory, $mod);
        }

        // Check for RIP-relative addressing (mod == 00, rm == 5)
        if ($mod === 0b00 && ($rm & 0b111) === 0b101) {
            // RIP-relative addressing in 64-bit mode
            $disp32 = $memory->signedDword();
            $rip = UInt64::of($runtime->memory()->offset());
            $address = $rip->add($this->signExtend32to64($disp32));
            return [$address->toInt(), $segment];
        }

        // Get base register value
        $baseRegType = $this->getRegisterType64($rm);
        $base = UInt64::of($runtime->memoryAccessor()->fetch($baseRegType)->asBytesBySize(64));

        // Add displacement based on mod
        $displacement = match ($mod) {
            0b00 => UInt64::zero(),
            0b01 => $this->signExtend8to64($memory->byte()),
            0b10 => $this->signExtend32to64($memory->signedDword()),
            default => UInt64::zero(),
        };

        $address = $base->add($displacement);
        return [$address->toInt(), $segment];
    }

    /**
     * Decode SIB byte for 64-bit addressing.
     */
    private function decodeSib64(RuntimeInterface $runtime, MemoryStreamInterface $memory, int $mod): array
    {
        $cpu = $runtime->context()->cpu();
        $sib = $memory->byte();

        $scale = ($sib >> 6) & 0b11;
        $index = ($sib >> 3) & 0b111;
        $base = $sib & 0b111;

        // Apply REX.X to index, REX.B to base
        if ($cpu->rexX()) {
            $index |= 0b1000;
        }
        if ($cpu->rexB()) {
            $base |= 0b1000;
        }

        $segment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        // Calculate base
        $baseValue = UInt64::zero();
        if ($mod === 0b00 && ($base & 0b111) === 0b101) {
            // No base register, disp32 follows
            $baseValue = $this->signExtend32to64($memory->signedDword());
        } else {
            $baseRegType = $this->getRegisterType64($base);
            $baseValue = UInt64::of($runtime->memoryAccessor()->fetch($baseRegType)->asBytesBySize(64));

            // Add displacement
            $displacement = match ($mod) {
                0b01 => $this->signExtend8to64($memory->byte()),
                0b10 => $this->signExtend32to64($memory->signedDword()),
                default => UInt64::zero(),
            };
            $baseValue = $baseValue->add($displacement);
        }

        // Calculate index (index == 4 without REX.X means no index)
        $indexValue = UInt64::zero();
        if ($index !== 0b0100) {
            $indexRegType = $this->getRegisterType64($index);
            $indexValue = UInt64::of($runtime->memoryAccessor()->fetch($indexRegType)->asBytesBySize(64));
            $indexValue = $indexValue->shl($scale);
        }

        $address = $baseValue->add($indexValue);
        return [$address->toInt(), $segment];
    }

    /**
     * Sign-extend 8-bit value to 64-bit.
     */
    private function signExtend8to64(int $value): UInt64
    {
        if (($value & 0x80) !== 0) {
            // Sign extend: set upper 56 bits
            return UInt64::of($value & 0xFF)->or('18446744073709551360'); // 0xFFFFFFFFFFFFFF00
        }
        return UInt64::of($value & 0xFF);
    }

    /**
     * Sign-extend 32-bit value to 64-bit.
     */
    private function signExtend32to64(int $value): UInt64
    {
        if (($value & 0x80000000) !== 0) {
            // Sign extend: set upper 32 bits
            return UInt64::of($value & 0xFFFFFFFF)->or('18446744069414584320'); // 0xFFFFFFFF00000000
        }
        return UInt64::of($value & 0xFFFFFFFF);
    }

    /**
     * Push value onto stack (64-bit mode).
     */
    protected function push64(RuntimeInterface $runtime, UInt64|int $value): void
    {
        $rspValue = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(64);
        $rsp = UInt64::of($rspValue)->sub(8);
        $runtime->memoryAccessor()->writeBySize(RegisterType::ESP, $rsp->toInt(), 64);
        $this->writeMemory64($runtime, $rsp->low32(), $value);
    }

    /**
     * Pop value from stack (64-bit mode).
     */
    protected function pop64(RuntimeInterface $runtime): UInt64
    {
        $rspValue = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(64);
        $rsp = UInt64::of($rspValue);
        $value = $this->readMemory64($runtime, $rsp->low32());
        $rsp = $rsp->add(8);
        $runtime->memoryAccessor()->writeBySize(RegisterType::ESP, $rsp->toInt(), 64);
        return $value;
    }
}
