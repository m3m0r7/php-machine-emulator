<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for register access operations.
 * Provides 8-bit, 16-bit, 32-bit, and 64-bit register read/write methods.
 * Used by both x86 and x86_64 instructions.
 */
trait RegisterAccessTrait
{
    /**
     * Decode 8-bit register code to RegisterType and high/low flag.
     *
     * @param int $register The 3-bit register code (0-7)
     * @return array{RegisterType, bool} [RegisterType, isHighByte]
     */
    protected function decode8BitRegister(int $register): array
    {
        return [
            match ($register & 0b11) {
                0b00 => RegisterType::EAX,
                0b01 => RegisterType::ECX,
                0b10 => RegisterType::EDX,
                0b11 => RegisterType::EBX,
            },
            ($register & 0b100) === 0b100, // true when targeting the high byte (AH/CH/DH/BH)
        ];
    }

    /**
     * Decode 8-bit register for 64-bit mode with REX prefix.
     * In 64-bit mode with REX prefix, registers 4-7 are SPL, BPL, SIL, DIL.
     *
     * @param int $register The register code
     * @param bool $hasRex Whether REX prefix is present
     * @param bool $rexB REX.B bit value
     * @return array{RegisterType, bool, bool} [RegisterType, isHighByte, isExtended]
     */
    protected function decode8BitRegister64(int $register, bool $hasRex, bool $rexB): array
    {
        // With REX, we can access R8B-R15B
        if ($hasRex && $rexB) {
            $extReg = $register | 0b1000;
            return [
                match ($extReg) {
                    0b1000 => RegisterType::R8,
                    0b1001 => RegisterType::R9,
                    0b1010 => RegisterType::R10,
                    0b1011 => RegisterType::R11,
                    0b1100 => RegisterType::R12,
                    0b1101 => RegisterType::R13,
                    0b1110 => RegisterType::R14,
                    0b1111 => RegisterType::R15,
                    default => RegisterType::EAX,
                },
                false,  // Low byte for extended registers
                true,   // Is extended register
            ];
        }

        // With REX but no REX.B, registers 4-7 are SPL, BPL, SIL, DIL (low bytes)
        if ($hasRex && $register >= 4 && $register <= 7) {
            return [
                match ($register) {
                    4 => RegisterType::ESP,
                    5 => RegisterType::EBP,
                    6 => RegisterType::ESI,
                    7 => RegisterType::EDI,
                },
                false,  // Low byte
                false,
            ];
        }

        // Standard 8-bit register decoding
        return [...$this->decode8BitRegister($register), false];
    }

    /**
     * Read value from an 8-bit register.
     */
    protected function read8BitRegister(RuntimeInterface $runtime, int $register): int
    {
        [$registerType, $isHigh] = $this->decode8BitRegister($register);
        $fetch = $runtime->memoryAccessor()->fetch($registerType);

        return $isHigh
            ? $fetch->asHighBit()    // AH/CH/DH/BH
            : $fetch->asLowBit();    // AL/CL/DL/BL
    }

    /**
     * Read value from an 8-bit register in 64-bit mode.
     */
    protected function read8BitRegister64(RuntimeInterface $runtime, int $register, bool $hasRex, bool $rexB): int
    {
        [$registerType, $isHigh, $isExtended] = $this->decode8BitRegister64($register, $hasRex, $rexB);
        $fetch = $runtime->memoryAccessor()->fetch($registerType);

        if ($isExtended) {
            return $fetch->asLowBit();
        }

        return $isHigh
            ? $fetch->asHighBit()
            : $fetch->asLowBit();
    }

    /**
     * Write value to an 8-bit register.
     */
    protected function write8BitRegister(RuntimeInterface $runtime, int $register, int $value, bool $updateFlags = true): void
    {
        [$registerType, $isHigh] = $this->decode8BitRegister($register);
        $memoryAccessor = $runtime->memoryAccessor();

        if (!$updateFlags) {
            $memoryAccessor->enableUpdateFlags(false);
        }

        if ($isHigh) {
            $memoryAccessor->writeToHighBit($registerType, $value);   // AH/CH/DH/BH
        } else {
            $memoryAccessor->writeToLowBit($registerType, $value);    // AL/CL/DL/BL
        }
    }

    /**
     * Write value to an 8-bit register in 64-bit mode.
     */
    protected function write8BitRegister64(RuntimeInterface $runtime, int $register, int $value, bool $hasRex, bool $rexB, bool $updateFlags = true): void
    {
        [$registerType, $isHigh, $isExtended] = $this->decode8BitRegister64($register, $hasRex, $rexB);
        $memoryAccessor = $runtime->memoryAccessor();

        if (!$updateFlags) {
            $memoryAccessor->enableUpdateFlags(false);
        }

        if ($isExtended) {
            $memoryAccessor->writeToLowBit($registerType, $value);
        } elseif ($isHigh) {
            $memoryAccessor->writeToHighBit($registerType, $value);
        } else {
            $memoryAccessor->writeToLowBit($registerType, $value);
        }
    }

    /**
     * Read value from a register by size.
     *
     * @param int $register The register code
     * @param int $size Operand size (8, 16, 32, or 64)
     */
    protected function readRegisterBySize(RuntimeInterface $runtime, int $register, int $size): int
    {
        return match ($size) {
            8 => $this->read8BitRegister($runtime, $register),
            16 => $runtime->memoryAccessor()->fetch($register)->asByte(),
            32 => $runtime->memoryAccessor()->fetch($register)->asBytesBySize(32),
            64 => $runtime->memoryAccessor()->fetch($register)->asBytesBySize(64),
            default => $runtime->memoryAccessor()->fetch($register)->asBytesBySize($size),
        };
    }

    /**
     * Write value to a register by size.
     *
     * @param int $register The register code
     * @param int $value The value to write
     * @param int $size Operand size (8, 16, 32, or 64)
     */
    protected function writeRegisterBySize(RuntimeInterface $runtime, int $register, int $value, int $size): void
    {
        match ($size) {
            8 => $this->write8BitRegister($runtime, $register, $value),
            16 => $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($register, $value),
            32 => $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($register, $value, 32),
            64 => $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($register, $value, 64),
            default => $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($register, $value, $size),
        };
    }

    /**
     * Read index register value (for string operations).
     */
    protected function readIndex(RuntimeInterface $runtime, RegisterType $register): int
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        return $runtime->memoryAccessor()->fetch($register)->asBytesBySize($addressSize);
    }

    /**
     * Write index register value (for string operations).
     */
    protected function writeIndex(RuntimeInterface $runtime, RegisterType $register, int $value): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $mask = match ($addressSize) {
            64 => 0xFFFFFFFFFFFFFFFF,
            32 => 0xFFFFFFFF,
            default => 0xFFFF,
        };
        $maskedValue = $value & $mask;

        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($register, $maskedValue, $addressSize);
    }

    /**
     * Calculate step direction for string operations.
     */
    protected function stepForElement(RuntimeInterface $runtime, int $bytes): int
    {
        return $runtime->memoryAccessor()->shouldDirectionFlag() ? -$bytes : $bytes;
    }
}
