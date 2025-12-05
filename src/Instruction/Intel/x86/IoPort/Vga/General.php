<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga;

/**
 * VGA General/Miscellaneous Registers.
 *
 * Several VGA ports are shared between different functions depending on
 * whether they are being read or written. This class uses constants
 * to represent both functions of shared ports.
 */
final class General
{
    // Miscellaneous Output Register
    public const MISC_OUTPUT_READ = 0x3CC;
    public const MISC_OUTPUT_WRITE = 0x3C2;

    // Input Status Register 0 (read from 0x3C2)
    public const INPUT_STATUS_0 = 0x3C2;

    // Input Status Register 1 (color/mono variants)
    // These ports are shared with Feature Control Write
    public const INPUT_STATUS_1_COLOR = 0x3DA;
    public const INPUT_STATUS_1_MONO = 0x3BA;

    // Feature Control Register
    public const FEATURE_CONTROL_READ = 0x3CA;
    public const FEATURE_CONTROL_WRITE_COLOR = 0x3DA;
    public const FEATURE_CONTROL_WRITE_MONO = 0x3BA;

    // VGA Enable Register
    public const VGA_ENABLE = 0x3C3;

    public static function isPort(int $port): bool
    {
        return $port === self::MISC_OUTPUT_READ
            || $port === self::MISC_OUTPUT_WRITE
            || $port === self::INPUT_STATUS_1_COLOR
            || $port === self::INPUT_STATUS_1_MONO
            || $port === self::FEATURE_CONTROL_READ
            || $port === self::VGA_ENABLE;
    }

    public static function isInputStatusPort(int $port): bool
    {
        return $port === self::INPUT_STATUS_0
            || $port === self::INPUT_STATUS_1_COLOR
            || $port === self::INPUT_STATUS_1_MONO;
    }
}
