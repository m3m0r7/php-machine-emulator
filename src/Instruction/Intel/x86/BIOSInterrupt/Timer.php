<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * INT 8 - System Timer Interrupt Handler (IRQ0)
 *
 * This interrupt is triggered by the PIT (Programmable Interval Timer) at ~18.2 Hz.
 * It updates the BIOS Data Area timer tick counter at 0x0040:0x006C (linear 0x46C).
 */
class Timer implements InterruptInterface
{
    // BDA timer tick counter address (0x0040:0x006C = linear 0x46C)
    private const BDA_TIMER_TICK = 0x46C;

    // BDA timer overflow flag (0x0040:0x0070 = linear 0x470)
    // Set when tick counter rolls over (24 hours elapsed)
    private const BDA_TIMER_OVERFLOW = 0x470;

    // Ticks per day: 18.2 Hz * 60 sec * 60 min * 24 hr ≈ 1,573,040
    private const TICKS_PER_DAY = 0x1800B0;

    public function process(RuntimeInterface $runtime): void
    {
        $mem = $runtime->memoryAccessor();

        // Read current tick count (4 bytes as DWORD)
        $b0 = $mem->readRawByte(self::BDA_TIMER_TICK) ?? 0;
        $b1 = $mem->readRawByte(self::BDA_TIMER_TICK + 1) ?? 0;
        $b2 = $mem->readRawByte(self::BDA_TIMER_TICK + 2) ?? 0;
        $b3 = $mem->readRawByte(self::BDA_TIMER_TICK + 3) ?? 0;
        $currentTick = $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);

        // Increment tick counter
        $newTick = $currentTick + 1;

        // Check for 24-hour rollover
        if ($newTick >= self::TICKS_PER_DAY) {
            $newTick = 0;
            // Set overflow flag
            $mem->writeBySize(self::BDA_TIMER_OVERFLOW, 0x01, 8);
        }

        // Write new tick count (4 bytes as DWORD)
        $mem->writeBySize(self::BDA_TIMER_TICK, $newTick & 0xFF, 8);
        $mem->writeBySize(self::BDA_TIMER_TICK + 1, ($newTick >> 8) & 0xFF, 8);
        $mem->writeBySize(self::BDA_TIMER_TICK + 2, ($newTick >> 16) & 0xFF, 8);
        $mem->writeBySize(self::BDA_TIMER_TICK + 3, ($newTick >> 24) & 0xFF, 8);

        // Send EOI (End of Interrupt) to PIC
        $picState = $runtime->context()->cpu()->picState();
        $picState->eoiMaster(0);
    }
}
