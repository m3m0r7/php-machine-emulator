<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga;

/**
 * VGA Sequencer (0x3C4-0x3C5).
 *
 * The Sequencer controls timing for display memory access.
 * Index register (0x3C4) selects one of the sequencer registers,
 * and data register (0x3C5) accesses the selected register.
 */
enum Sequencer: int
{
    case INDEX = 0x3C4;
    case DATA = 0x3C5;

    public static function isPort(int $port): bool
    {
        return $port === self::INDEX->value || $port === self::DATA->value;
    }
}
