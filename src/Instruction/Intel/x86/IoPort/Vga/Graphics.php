<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga;

/**
 * VGA Graphics Controller (0x3CE-0x3CF).
 *
 * The Graphics Controller is responsible for the interface between
 * the CPU and the video memory.
 */
enum Graphics: int
{
    case INDEX = 0x3CE;
    case DATA = 0x3CF;

    public static function isPort(int $port): bool
    {
        return $port === self::INDEX->value || $port === self::DATA->value;
    }
}
