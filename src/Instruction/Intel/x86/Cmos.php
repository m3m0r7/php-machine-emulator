<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use Carbon\CarbonImmutable;
use PHPMachineEmulator\Instruction\Intel\x86\Cmos\Register;

class Cmos
{
    private int $index = 0;

    /** @var array<int, int> Writable CMOS registers (overrides) */
    private array $registers = [];

    /** @var CarbonImmutable|null Custom time offset (null = use system time) */
    private ?CarbonImmutable $timeOffset = null;

    public function writeIndex(int $value): void
    {
        // bit7 = NMI disable (ignored)
        $this->index = $value & 0x7F;
    }

    public function read(): int
    {
        return $this->registerValue($this->index);
    }

    public function write(int $value): void
    {
        $this->writeRegister($this->index, $value);
    }

    public function writeRegister(int $index, int $value): void
    {
        $this->registers[$index] = $value & 0xFF;

        // When time/date registers are written, update the time offset
        $this->updateTimeOffsetFromRegisters();
    }

    public function readRegister(int $index): int
    {
        return $this->registerValue($index);
    }

    /**
     * Set time directly (used by INT 1Ah).
     */
    public function setTime(int $hours, int $minutes, int $seconds): void
    {
        $now = $this->getCurrentTime();
        $this->timeOffset = $now->setTime($hours, $minutes, $seconds);
    }

    /**
     * Set date directly (used by INT 1Ah).
     */
    public function setDate(int $year, int $month, int $day): void
    {
        $now = $this->getCurrentTime();
        $this->timeOffset = $now->setDate($year, $month, $day);
    }

    /**
     * Get the current time (respecting any offset).
     */
    public function getCurrentTime(): CarbonImmutable
    {
        return $this->timeOffset ?? CarbonImmutable::now();
    }

    private function registerValue(int $index): int
    {
        // Check if register was explicitly written
        if (isset($this->registers[$index])) {
            return $this->registers[$index];
        }

        $now = $this->getCurrentTime();

        return match ($index) {
            Register::SECONDS->value => $now->second,
            Register::MINUTES->value => $now->minute,
            Register::HOURS->value => $now->hour,
            Register::DAY_OF_WEEK->value => $now->dayOfWeek + 1, // 1-7 (Sunday = 1)
            Register::DAY_OF_MONTH->value => $now->day,
            Register::MONTH->value => $now->month,
            Register::YEAR->value => $now->year % 100,
            Register::CENTURY->value => (int) ($now->year / 100),
            Register::STATUS_A->value => 0x26, // 32KHz, divider
            Register::STATUS_B->value => 0x02, // 24-hour, binary mode
            Register::STATUS_C->value => 0x00, // Interrupt flags (cleared)
            Register::STATUS_D->value => 0x80, // Valid RAM, power good
            Register::FLOPPY_DRIVE_TYPE->value => 0x00,
            default => 0x00,
        };
    }

    /**
     * Update time offset when registers are written directly.
     */
    private function updateTimeOffsetFromRegisters(): void
    {
        // Only update if all necessary time registers are set
        $hasTime = isset($this->registers[Register::HOURS->value])
            && isset($this->registers[Register::MINUTES->value])
            && isset($this->registers[Register::SECONDS->value]);

        $hasDate = isset($this->registers[Register::YEAR->value])
            && isset($this->registers[Register::MONTH->value])
            && isset($this->registers[Register::DAY_OF_MONTH->value]);

        if ($hasTime || $hasDate) {
            $now = $this->timeOffset ?? CarbonImmutable::now();

            if ($hasTime) {
                $now = $now->setTime(
                    $this->registers[Register::HOURS->value],
                    $this->registers[Register::MINUTES->value],
                    $this->registers[Register::SECONDS->value],
                );
            }

            if ($hasDate) {
                $year = $this->registers[Register::YEAR->value];
                // Use century register if available, otherwise assume 20xx
                $century = $this->registers[Register::CENTURY->value] ?? 20;
                $fullYear = $century * 100 + $year;

                $now = $now->setDate(
                    $fullYear,
                    $this->registers[Register::MONTH->value],
                    $this->registers[Register::DAY_OF_MONTH->value],
                );
            }

            $this->timeOffset = $now;
        }
    }
}
