<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\RegisterNotFoundException;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Register mapping for x86/x64 architecture.
 *
 * Address layout:
 *   0-7:   GPRs (EAX-EDI / RAX-RDI) - stored as 64-bit
 *   8-13:  Segment registers (ES, CS, SS, DS, FS, GS) - stored as 16-bit
 *   16-23: Extended GPRs (R8-R15) - stored as 64-bit, 64-bit mode only
 *   24:    RIP (Instruction Pointer) - stored as 64-bit
 *   25:    EDI_ON_MEMORY (special)
 */
class Register implements RegisterInterface
{
    /**
     * Offset for segment registers (addresses 8-13).
     */
    public static function getRaisedSegmentRegister(): int
    {
        return 0b1000;  // 8
    }

    /**
     * Offset for special destination register (EDI_ON_MEMORY).
     */
    public static function getRaisedDestinationRegister(): int
    {
        return 0b10000;  // 16
    }

    /**
     * Offset for extended 64-bit registers (R8-R15).
     * These are at addresses 16-23.
     */
    public static function getRaisedExtendedRegister(): int
    {
        return 0b10000;  // 16
    }

    /**
     * Address for RIP register.
     */
    public static function getRipAddress(): int
    {
        return 24;
    }

    public static function find(int $register): RegisterType
    {
        foreach (self::map() as $name => $value) {
            if ($register === $value) {
                return constant(RegisterType::class . '::' . $name);
            }
        }

        throw new RegisterNotFoundException('Register not found');
    }

    /**
     * Find GPR by 3-bit code (0-7) with optional REX.B extension.
     *
     * @param int $code 3-bit register code (0-7)
     * @param bool $rexB REX.B bit for extended register access (R8-R15)
     * @return RegisterType
     */
    public static function findGprByCode(int $code, bool $rexB = false): RegisterType
    {
        $code = $code & 0b111;

        if ($rexB) {
            // R8-R15
            return match ($code) {
                0b000 => RegisterType::R8,
                0b001 => RegisterType::R9,
                0b010 => RegisterType::R10,
                0b011 => RegisterType::R11,
                0b100 => RegisterType::R12,
                0b101 => RegisterType::R13,
                0b110 => RegisterType::R14,
                0b111 => RegisterType::R15,
            };
        }

        // EAX-EDI / RAX-RDI
        return match ($code) {
            0b000 => RegisterType::EAX,
            0b001 => RegisterType::ECX,
            0b010 => RegisterType::EDX,
            0b011 => RegisterType::EBX,
            0b100 => RegisterType::ESP,
            0b101 => RegisterType::EBP,
            0b110 => RegisterType::ESI,
            0b111 => RegisterType::EDI,
        };
    }

    public static function map(): array
    {
        return [
            // Legacy GPRs (addresses 0-7)
            RegisterType::EAX->name => 0b000,
            RegisterType::ECX->name => 0b001,
            RegisterType::EDX->name => 0b010,
            RegisterType::EBX->name => 0b011,
            RegisterType::ESP->name => 0b100,
            RegisterType::EBP->name => 0b101,
            RegisterType::ESI->name => 0b110,
            RegisterType::EDI->name => 0b111,

            // Extended GPRs R8-R15 (addresses 16-23)
            RegisterType::R8->name => 0b000 + self::getRaisedExtendedRegister(),
            RegisterType::R9->name => 0b001 + self::getRaisedExtendedRegister(),
            RegisterType::R10->name => 0b010 + self::getRaisedExtendedRegister(),
            RegisterType::R11->name => 0b011 + self::getRaisedExtendedRegister(),
            RegisterType::R12->name => 0b100 + self::getRaisedExtendedRegister(),
            RegisterType::R13->name => 0b101 + self::getRaisedExtendedRegister(),
            RegisterType::R14->name => 0b110 + self::getRaisedExtendedRegister(),
            RegisterType::R15->name => 0b111 + self::getRaisedExtendedRegister(),

            // NOTE: In this project, directly writing to the file stream would overwrite the file itself,
            //       so we internally maintain registers that can be modified in memory.
            //       This allows efficient operations on the DI register.
            // Using address 25 (after RIP at 24) to avoid collision with R8-R15 (16-23)
            RegisterType::EDI_ON_MEMORY->name => 25,

            // Segment registers (addresses 8-13)
            RegisterType::ES->name => 0b0000 + self::getRaisedSegmentRegister(),
            RegisterType::CS->name => 0b0001 + self::getRaisedSegmentRegister(),
            RegisterType::SS->name => 0b0010 + self::getRaisedSegmentRegister(),
            RegisterType::DS->name => 0b0011 + self::getRaisedSegmentRegister(),
            RegisterType::FS->name => 0b0100 + self::getRaisedSegmentRegister(),
            RegisterType::GS->name => 0b0101 + self::getRaisedSegmentRegister(),

            // Instruction Pointer (address 24)
            RegisterType::RIP->name => self::getRipAddress(),
        ];
    }

    public static function addressBy(RegisterType $type): int
    {
        return static::map()[$type->name] ?? throw new RegisterNotFoundException('Register not found');
    }

    /**
     * Check if the register type is an extended register (R8-R15).
     */
    public static function isExtendedGpr(RegisterType $type): bool
    {
        return in_array($type, [
            RegisterType::R8,
            RegisterType::R9,
            RegisterType::R10,
            RegisterType::R11,
            RegisterType::R12,
            RegisterType::R13,
            RegisterType::R14,
            RegisterType::R15,
        ], true);
    }
}
