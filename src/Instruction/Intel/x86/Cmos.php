<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

class Cmos
{
    private int $index = 0;

    public function writeIndex(int $value): void
    {
        // bit7 = NMI disable (ignored)
        $this->index = $value & 0x7F;
    }

    public function read(): int
    {
        return $this->registerValue($this->index);
    }

    private function registerValue(int $index): int
    {
        // Minimal RTC/CMOS values
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return match ($index) {
            0x00 => (int) $now->format('s'), // seconds (BCD not enforced)
            0x02 => (int) $now->format('i'), // minutes
            0x04 => (int) $now->format('H'), // hours
            0x07 => (int) $now->format('d'), // day of month
            0x08 => (int) $now->format('n'), // month
            0x09 => (int) $now->format('y'), // year (00-99)
            0x0A => 0x26, // Status A: 32KHz, divider
            0x0B => 0x02, // Status B: 24-hour
            0x0E => 0x00, // Status D: power good
            0x10 => 0x00, // equipment byte placeholder
            default => 0x00,
        };
    }
}
