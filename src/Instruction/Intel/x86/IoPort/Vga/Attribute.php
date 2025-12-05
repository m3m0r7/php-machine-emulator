<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga;

/**
 * VGA Attribute Controller (0x3C0-0x3C1).
 *
 * The Attribute Controller controls the palette, mode, and overscan color.
 * Note: 0x3C0 is used for both index and data write (flip-flop controlled).
 */
enum Attribute: int
{
    case INDEX_DATA_WRITE = 0x3C0;
    case DATA_READ = 0x3C1;

    public static function isPort(int $port): bool
    {
        return $port === self::INDEX_DATA_WRITE->value || $port === self::DATA_READ->value;
    }
}
