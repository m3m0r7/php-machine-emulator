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
    ) {
    }

    /**
     * BIOS timer tick rate (~18.2065 Hz).
     *
     * 24h rollover: 18.2065 * 60 * 60 * 24 ≈ 1,573,040 ticks (0x1800B0).
     */
    private const PIT_TICK_HZ = 18.206481;

    private float $lastTickTimeSec = 0.0;
    private float $fractionalTicks = 0.0;

    public function tick(RuntimeInterface $runtime): void
    {
        // Advance BIOS tick counter in (approximate) real time so bootloader timeouts
        // behave correctly even when the emulator executes slowly in PHP.
        $now = microtime(true);
        if ($this->lastTickTimeSec <= 0.0) {
            $this->lastTickTimeSec = $now;
            return;
        }

        $elapsed = $now - $this->lastTickTimeSec;
        if ($elapsed <= 0.0) {
            return;
        }
        $this->lastTickTimeSec = $now;

        $this->fractionalTicks += $elapsed * self::PIT_TICK_HZ;
        $ticks = (int) floor($this->fractionalTicks);
        if ($ticks <= 0) {
            return;
        }
        $this->fractionalTicks -= $ticks;

        $this->addBdaTicks($runtime, $ticks);
    }

    /**
     * Advance BDA timer tick counter (like INT 8 handler would do).
     */
    private function addBdaTicks(RuntimeInterface $runtime, int $delta): void
    {
        if ($delta <= 0) {
            return;
        }

        $mem = $runtime->memoryAccessor();

        // Read current tick count (32-bit DWORD at 0x46C)
        $b0 = $mem->readRawByte(self::BDA_TIMER_TICK) ?? 0;
        $b1 = $mem->readRawByte(self::BDA_TIMER_TICK + 1) ?? 0;
        $b2 = $mem->readRawByte(self::BDA_TIMER_TICK + 2) ?? 0;
        $b3 = $mem->readRawByte(self::BDA_TIMER_TICK + 3) ?? 0;
        $tickCount = $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);

        $tickCount = ($tickCount + $delta);

        // Check for 24-hour overflow
        if ($tickCount >= self::TICKS_PER_DAY) {
            $tickCount %= self::TICKS_PER_DAY;
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
