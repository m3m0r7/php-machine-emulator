<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

class ApicState
{
    private int $apicBase = 0xFEE00000;
    private bool $apicEnabled = false;

    // Local APIC registers
    private int $id = 0x0;
    private int $version = 0x00050011; // dummy version
    private int $svr = 0x00000100; // enable bit
    private int $lvtTimer = 0x10000; // masked
    private int $initialCount = 0;
    private int $currentCount = 0;
    private int $divide = 0;
    private float $lastUpdateTimeSec = 0.0;
    private float $fractionalTicks = 0.0;
    private array $lapicRegs = [];

    // IOAPIC
    private int $ioapicIndex = 0;
    private array $ioapicRegs = [];
    private array $pending = [];
    private ?int $inService = null;
    private const TIMER_BASE_HZ = 1000000.0;
    private const IOAPIC_ID = 0x17;
    private const IOAPIC_VERSION = 0x11; // 24 redirection entries
    private array $irr = [0, 0, 0, 0, 0, 0, 0, 0]; // 256 bits
    private array $isr = [0, 0, 0, 0, 0, 0, 0, 0];
    private array $levelAsserted = [];

    public function setApicBase(int $base, bool $enable): void
    {
        $this->apicBase = $base & 0xFFFFF000;
        $this->apicEnabled = $enable;
    }

    public function apicEnabled(): bool
    {
        return $this->apicEnabled;
    }

    public function readMsrApicBase(): int
    {
        $val = $this->apicBase | ($this->apicEnabled ? (1 << 11) : 0);
        return $val;
    }

    public function readLapic(int $offset, int $width): int
    {
        $reg = $this->lapicRegs[$offset] ?? 0;

        $register = LapicRegister::tryFrom($offset);
        if ($register !== null) {
            $reg = match ($register) {
                LapicRegister::ID => $this->id,
                LapicRegister::Version => $this->version,
                LapicRegister::SpuriousInterruptVector => $this->svr,
                LapicRegister::LvtTimer => $this->lvtTimer,
                LapicRegister::TimerInitialCount => $this->initialCount,
                LapicRegister::TimerCurrentCount => $this->currentCount,
                LapicRegister::TimerDivideConfiguration => $this->divide,
                default => $reg,
            };
        }

        return $width === 8 ? $reg & 0xFF : ($width === 16 ? $reg & 0xFFFF : $reg);
    }

    public function writeLapic(int $offset, int $value, int $width): void
    {
        $val = $width === 8 ? $value & 0xFF : ($width === 16 ? $value & 0xFFFF : $value & 0xFFFFFFFF);

        $register = LapicRegister::tryFrom($offset);
        if ($register === null) {
            $this->lapicRegs[$offset] = $val;
            return;
        }

        match ($register) {
            LapicRegister::EndOfInterrupt => $this->handleEndOfInterrupt(),
            LapicRegister::SpuriousInterruptVector => $this->handleSpuriousInterruptVectorWrite($val),
            LapicRegister::LvtTimer => $this->lvtTimer = $val,
            LapicRegister::TimerInitialCount => $this->handleTimerInitialCountWrite($val),
            LapicRegister::TimerCurrentCount => $this->currentCount = $val,
            LapicRegister::TimerDivideConfiguration => $this->divide = $val & 0xF,
            default => $this->lapicRegs[$offset] = $val,
        };
    }

    private function handleEndOfInterrupt(): void
    {
        if ($this->inService !== null) {
            $this->setBit($this->isr, $this->inService, false);
        }
        $this->inService = null;
        // Clear level-triggered asserted flags to allow re-delivery.
        $this->levelAsserted = [];
    }

    private function handleSpuriousInterruptVectorWrite(int $val): void
    {
        $this->svr = $val;
        $this->apicEnabled = ($val & 0x100) !== 0;
    }

    private function handleTimerInitialCountWrite(int $val): void
    {
        $this->initialCount = $val;
        $this->currentCount = $val;
    }

    public function advanceFromHostTime(?callable $deliverInterrupt = null): void
    {
        if (!$this->apicEnabled) {
            return;
        }

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

        $divider = $this->apicTimerDivider();
        $hz = self::TIMER_BASE_HZ / max(1, $divider);
        $this->fractionalTicks += $elapsed * $hz;
        $ticks = (int) floor($this->fractionalTicks);
        if ($ticks <= 0) {
            return;
        }
        $this->fractionalTicks -= $ticks;

        $this->tick($deliverInterrupt, $ticks);
    }

    public function tick(?callable $deliverInterrupt, int $ticks = 1): void
    {
        if (!$this->apicEnabled) {
            return;
        }
        // Timer masked?
        if (($this->lvtTimer & 0x10000) !== 0) {
            return;
        }
        if ($this->initialCount === 0) {
            return;
        }

        $remaining = max(1, $ticks);
        while ($remaining > 0) {
            if ($this->currentCount <= 0) {
                $this->currentCount = $this->initialCount;
                if ($this->currentCount <= 0) {
                    return;
                }
            }

            if ($remaining < $this->currentCount) {
                $this->currentCount -= $remaining;
                return;
            }

            $remaining -= $this->currentCount;
            $this->currentCount = 0;

            $vector = $this->lvtTimer & 0xFF;
            if ($deliverInterrupt) {
                $deliverInterrupt($vector);
            } else {
                $this->queueVector($vector);
            }

            // Periodic if bit 17 set
            if (($this->lvtTimer & (1 << 17)) !== 0) {
                $this->currentCount = $this->initialCount;
            } else {
                return;
            }
        }
    }

    private function apicTimerDivider(): int
    {
        $divide = $this->divide & 0xF;
        return match ($divide) {
            0b0000 => 2,
            0b0001 => 4,
            0b0010 => 8,
            0b0011 => 16,
            0b1000 => 32,
            0b1001 => 64,
            0b1010 => 128,
            0b1011 => 1,
            default => 1,
        };
    }

    public function pendingVector(): ?int
    {
        if ($this->inService !== null) {
            return null;
        }
        while (($vec = array_shift($this->pending)) !== null) {
            if (!$this->testBit($this->irr, $vec)) {
                continue;
            }
            $this->setBit($this->irr, $vec, false);
            $this->setBit($this->isr, $vec, true);
            $this->inService = $vec;
            return $vec;
        }
        return null;
    }

    public function queueVector(int $vector): void
    {
        if ($this->testBit($this->irr, $vector) || $this->testBit($this->isr, $vector)) {
            return;
        }
        $this->setBit($this->irr, $vector, true);
        $this->pending[] = $vector & 0xFF;
    }

    public function writeIoapicIndex(int $value): void
    {
        $this->ioapicIndex = $value & 0xFF;
    }

    public function writeIoapicData(int $value): void
    {
        $reg = $this->ioapicIndex & 0xFF;
        $this->ioapicRegs[$reg] = $value & 0xFFFFFFFF;
    }

    public function readIoapicIndex(): int
    {
        return $this->ioapicIndex & 0xFF;
    }

    public function readIoapicData(): int
    {
        $reg = $this->ioapicIndex & 0xFF;
        return match ($reg) {
            0x00 => self::IOAPIC_ID << 24,
            0x01 => 0, // arbitration ID (ignored)
            0x02 => (self::IOAPIC_VERSION & 0xFF) | ((24 - 1) << 16),
            default => $this->ioapicRegs[$reg] ?? 0,
        };
    }

    public function raiseIoapicIrq(int $irq): void
    {
        if (!$this->apicEnabled) {
            return;
        }
        $entry = $this->getIoapicEntry($irq);
        $masked = ($entry['lo'] & (1 << 16)) !== 0;
        if ($masked) {
            return;
        }
        $level = ($entry['lo'] & (1 << 15)) !== 0;
        if ($level && ($this->levelAsserted[$irq] ?? false)) {
            return;
        }
        $vector = $entry['lo'] & 0xFF;
        if ($level) {
            $this->levelAsserted[$irq] = true;
        }
        $this->queueVector($vector);
    }

    private function getIoapicEntry(int $irq): array
    {
        $lowReg = 0x10 + ($irq * 2);
        $highReg = $lowReg + 1;
        $lo = $this->ioapicRegs[$lowReg] ?? ((0x20 + $irq) & 0xFF);
        $hi = $this->ioapicRegs[$highReg] ?? 0;
        return ['lo' => $lo, 'hi' => $hi];
    }

    private function setBit(array &$arr, int $vec, bool $value): void
    {
        $idx = ($vec >> 5) & 0x7;
        $bit = 1 << ($vec & 0x1F);
        if ($value) {
            $arr[$idx] |= $bit;
        } else {
            $arr[$idx] &= ~$bit;
        }
    }

    private function testBit(array $arr, int $vec): bool
    {
        $idx = ($vec >> 5) & 0x7;
        $bit = 1 << ($vec & 0x1F);
        return ($arr[$idx] & $bit) !== 0;
    }
}
