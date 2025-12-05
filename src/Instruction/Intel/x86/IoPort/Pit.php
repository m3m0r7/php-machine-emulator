<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort;

/**
 * PIT (Programmable Interval Timer) 8253/8254 I/O ports.
 *
 * The PIT provides three independent 16-bit counters:
 * - Channel 0: System timer (IRQ0)
 * - Channel 1: DRAM refresh (legacy, not used in modern systems)
 * - Channel 2: PC speaker
 */
enum Pit: int
{
    case CHANNEL_0 = 0x40;
    case CHANNEL_1 = 0x41;
    case CHANNEL_2 = 0x42;
    case CONTROL = 0x43;

    public static function isPort(int $port): bool
    {
        return $port >= self::CHANNEL_0->value && $port <= self::CONTROL->value;
    }

    public static function channelFromPort(int $port): int
    {
        return $port - self::CHANNEL_0->value;
    }
}
