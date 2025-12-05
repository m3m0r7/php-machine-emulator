<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Ticker;

use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Pit;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Ticker for PIT (Programmable Interval Timer) operations.
 *
 * Instead of raising IRQ0 and going through IVT, this ticker directly
 * updates the BDA timer tick counter. This is simpler than implementing
 * real x86 interrupt handlers in memory.
 */
class PitTicker implements TickerInterface
{
    private const BDA_TIMER_TICK = 0x46C;
    private const BDA_TIMER_OVERFLOW = 0x470;
    private const TICKS_PER_DAY = 0x1800B0; // ~1,573,040 ticks per 24 hours

    public function __construct(
        private readonly Pit $pit,
    ) {}

    public function tick(RuntimeInterface $runtime): void
    {
        // For now, increment BDA timer every tick for faster timer updates
        // TODO: This should be tied to actual PIT counter reaching 0
        $this->incrementBdaTimer($runtime);
    }

    /**
     * Increment BDA timer tick counter (like INT 8 handler would do).
     */
    private function incrementBdaTimer(RuntimeInterface $runtime): void
    {
        $mem = $runtime->memoryAccessor();

        // Read current tick count (32-bit DWORD at 0x46C)
        $b0 = $mem->readRawByte(self::BDA_TIMER_TICK) ?? 0;
        $b1 = $mem->readRawByte(self::BDA_TIMER_TICK + 1) ?? 0;
        $b2 = $mem->readRawByte(self::BDA_TIMER_TICK + 2) ?? 0;
        $b3 = $mem->readRawByte(self::BDA_TIMER_TICK + 3) ?? 0;
        $tickCount = $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);

        // Increment
        $tickCount++;

        // Check for 24-hour overflow
        if ($tickCount >= self::TICKS_PER_DAY) {
            $tickCount = 0;
            $mem->writeBySize(self::BDA_TIMER_OVERFLOW, 1, 8);
        }

        // Write back
        $mem->writeBySize(self::BDA_TIMER_TICK, $tickCount & 0xFF, 8);
        $mem->writeBySize(self::BDA_TIMER_TICK + 1, ($tickCount >> 8) & 0xFF, 8);
        $mem->writeBySize(self::BDA_TIMER_TICK + 2, ($tickCount >> 16) & 0xFF, 8);
        $mem->writeBySize(self::BDA_TIMER_TICK + 3, ($tickCount >> 24) & 0xFF, 8);
    }

    public function interval(): int
    {
        return 0;
    }
}
