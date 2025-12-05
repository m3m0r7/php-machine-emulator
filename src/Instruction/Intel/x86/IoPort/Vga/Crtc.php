<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga;

/**
 * VGA CRT Controller (0x3D4-0x3D5 color, 0x3B4-0x3B5 mono).
 *
 * The CRT Controller generates horizontal and vertical synchronization
 * signals, controls the cursor shape and position.
 */
enum Crtc: int
{
    // Color mode ports (standard)
    case INDEX_COLOR = 0x3D4;
    case DATA_COLOR = 0x3D5;

    // Monochrome mode ports
    case INDEX_MONO = 0x3B4;
    case DATA_MONO = 0x3B5;

    public static function isPort(int $port): bool
    {
        return $port === self::INDEX_COLOR->value
            || $port === self::DATA_COLOR->value
            || $port === self::INDEX_MONO->value
            || $port === self::DATA_MONO->value;
    }

    public static function isColorPort(int $port): bool
    {
        return $port === self::INDEX_COLOR->value || $port === self::DATA_COLOR->value;
    }

    public static function isMonoPort(int $port): bool
    {
        return $port === self::INDEX_MONO->value || $port === self::DATA_MONO->value;
    }
}
