<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga;

/**
 * VGA DAC (Digital-to-Analog Converter) (0x3C7-0x3C9).
 *
 * The DAC converts the digital color values to analog signals.
 * Note: Port 0x3C7 is shared - write sets read index, read returns DAC state.
 */
enum Dac: int
{
    case WRITE_INDEX = 0x3C8;
    case DATA = 0x3C9;

    public static function isPort(int $port): bool
    {
        return $port >= 0x3C7 && $port <= self::DATA->value;
    }

    /**
     * Port 0x3C7 behavior:
     * - Write: Sets the DAC read index
     * - Read: Returns DAC state (bits 0-1: 00=write, 11=read mode)
     */
    public const READ_INDEX_STATE = 0x3C7;
}
