<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\TimeOfDay;

/**
 * BIOS Data Area (BDA) addresses for Time of Day.
 *
 * The BDA is located at segment 0x0040 (linear address 0x400).
 * Timer-related fields are at offset 0x6C and 0x70.
 */
enum BdaAddress: int
{
    /**
     * Timer tick counter (DWORD at 0x0040:0x006C = linear 0x46C).
     * Incremented approximately 18.2 times per second by IRQ0.
     * Counts ticks since midnight (resets at 0x001800B0 = 1573040 ticks/day).
     */
    case TIMER_TICK = 0x46C;

    /**
     * Timer overflow/midnight flag (BYTE at 0x0040:0x0070 = linear 0x470).
     * Set to non-zero when tick counter wraps past midnight.
     * Cleared after being read by INT 0x1A AH=0x00.
     */
    case TIMER_OVERFLOW = 0x470;
}
