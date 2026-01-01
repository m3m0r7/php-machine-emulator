<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

class Pit
{
    /**
     * PIT base clock (~1.193182 MHz).
     *
     * Many bootloaders poll the PIT counter via ports 0x40/0x41/0x42 and expect
     * the counter to advance in real time, even if we do not model IRQ0 delivery.
     */
    private const BASE_HZ = 1193182.0;

    private int $counter0 = 0;
    private int $reload0 = 0;
    private int $mode0 = 2; // default mode 2
    private int $accessMode0 = 3; // low/high
    private bool $bcd0 = false;
    private ?int $latchedCount0 = null;
    private int $readFlipFlop0 = 0;
    private int $writeFlipFlop0 = 0;
    private float $lastUpdateTimeSec = 0.0;
    private float $fractionalBaseTicks = 0.0;

    public function writeControl(int $value): void
    {
        $this->advanceFromHostTime();

        $channel = ($value >> 6) & 0x3;
        if ($channel !== 0) {
            return; // only channel 0 modeled
        }

        $access = ($value >> 4) & 0x3;
        $mode = ($value >> 1) & 0x7;
        $bcd = ($value & 0x1) !== 0;

        if ($access === 0) {
            // latch count
            $this->latchedCount0 = $this->counter0;
            $this->readFlipFlop0 = 0;
            return;
        }

        $this->accessMode0 = $access;
        $this->mode0 = $mode;
        $this->bcd0 = $bcd;
        $this->writeFlipFlop0 = 0;
    }

    public function writeChannel(int $channel, int $value): void
    {
        $this->advanceFromHostTime();

        if ($channel === 0) {
            $val = $value & 0xFF;
            if ($this->accessMode0 === 1) { // lobyte
                $this->reload0 = ($this->reload0 & 0xFF00) | $val;
                $this->loadCounter0();
            } elseif ($this->accessMode0 === 2) { // hibyte
                $this->reload0 = ($this->reload0 & 0x00FF) | ($val << 8);
                $this->loadCounter0();
            } else { // low/high
                if ($this->writeFlipFlop0 === 0) {
                    $this->reload0 = ($this->reload0 & 0xFF00) | $val;
                    $this->writeFlipFlop0 = 1;
                } else {
                    $this->reload0 = ($this->reload0 & 0x00FF) | ($val << 8);
                    $this->writeFlipFlop0 = 0;
                    $this->loadCounter0();
                }
            }
        }
    }

    public function tick(?callable $irq0 = null): void
    {
        $this->advanceCounter0ByBaseTicks(1, $irq0);
    }

    public function readCounter(): int
    {
        $this->advanceFromHostTime();

        $value = $this->latchedCount0 ?? $this->counter0;
        $ret = 0;
        if ($this->accessMode0 === 2) { // hibyte only
            $ret = ($value >> 8) & 0xFF;
        } elseif ($this->accessMode0 === 1) { // lobyte only
            $ret = $value & 0xFF;
        } else { // low/high
            if ($this->readFlipFlop0 === 0) {
                $ret = $value & 0xFF;
                $this->readFlipFlop0 = 1;
            } else {
                $ret = ($value >> 8) & 0xFF;
                $this->readFlipFlop0 = 0;
                $this->latchedCount0 = null;
            }
        }
        return $ret;
    }

    private function advanceFromHostTime(): void
    {
        $now = microtime(true);
        if ($this->lastUpdateTimeSec <= 0.0) {
            $this->lastUpdateTimeSec = $now;
            return;
        }

        $elapsed = $now - $this->lastUpdateTimeSec;
        if ($elapsed <= 0.0) {
            return;
        }
        $this->lastUpdateTimeSec = $now;

        $this->fractionalBaseTicks += $elapsed * self::BASE_HZ;
        $ticks = (int) floor($this->fractionalBaseTicks);
        if ($ticks <= 0) {
            return;
        }
        $this->fractionalBaseTicks -= $ticks;

        // Advance channel 0 only (no IRQ0 delivery here; BDA ticks are handled elsewhere).
        $this->advanceCounter0ByBaseTicks($ticks, null);
    }

    private function advanceCounter0ByBaseTicks(int $ticks, ?callable $irq0): void
    {
        if ($ticks <= 0) {
            return;
        }

        $reload = $this->reload0 === 0 ? 0x10000 : ($this->reload0 & 0xFFFF);
        if ($reload <= 0) {
            $reload = 0x10000;
        }

        if ($this->counter0 <= 0) {
            $this->counter0 = $reload;
        }

        // Fast advance: keep counter moving (and optionally deliver IRQ0 for each rollover).
        if ($ticks >= $this->counter0) {
            $ticks -= $this->counter0;
            if ($irq0 !== null) {
                $irq0();
            }

            if ($reload > 0) {
                if ($ticks >= $reload) {
                    if ($irq0 !== null) {
                        $wraps = intdiv($ticks, $reload);
                        for ($i = 0; $i < $wraps; $i++) {
                            $irq0();
                        }
                    }
                    $ticks %= $reload;
                }
            }

            $this->counter0 = $reload;
        }

        if ($ticks <= 0) {
            return;
        }

        $this->counter0 -= $ticks;
        if ($this->counter0 <= 0) {
            if ($irq0 !== null) {
                $irq0();
            }
            $this->counter0 = $reload;
        }
    }

    private function loadCounter0(): void
    {
        $this->counter0 = $this->reload0 === 0 ? 0x10000 : $this->reload0;
        $this->latchedCount0 = null;
        $this->readFlipFlop0 = 0;
    }
}
