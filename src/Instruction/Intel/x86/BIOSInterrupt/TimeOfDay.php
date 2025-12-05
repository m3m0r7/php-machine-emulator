<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * INT 0x1A - Time of Day Services
 *
 * This BIOS interrupt provides access to the system timer tick counter
 * and the Real-Time Clock (RTC).
 *
 * Functions:
 * - AH=0x00: Read System Timer Counter (returns tick count since midnight)
 * - AH=0x01: Set System Timer Counter
 * - AH=0x02: Read RTC Time (returns hours, minutes, seconds in BCD)
 * - AH=0x03: Set RTC Time
 * - AH=0x04: Read RTC Date (returns year, month, day in BCD)
 * - AH=0x05: Set RTC Date
 */
class TimeOfDay implements InterruptInterface
{
    // BDA timer tick counter address (0x0040:0x006C = linear 0x46C)
    private const BDA_TIMER_TICK = 0x46C;

    // BDA timer overflow flag (0x0040:0x0070 = linear 0x470)
    private const BDA_TIMER_OVERFLOW = 0x470;

    public function process(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $eax = $ma->fetch(RegisterType::EAX)->asByte();
        $ah = ($eax >> 8) & 0xFF;

        $runtime->option()->logger()->debug(sprintf(
            'INT 0x1A: AH=0x%02X EAX=0x%08X',
            $ah,
            $eax
        ));

        switch ($ah) {
            case 0x00:
                // Read System Timer Counter
                $this->readSystemTimerCounter($runtime);
                break;

            case 0x01:
                // Set System Timer Counter
                $this->setSystemTimerCounter($runtime);
                break;

            case 0x02:
                // Read RTC Time (hours, minutes, seconds in BCD)
                $this->readRtcTime($runtime);
                break;

            case 0x03:
                // Set RTC Time - just acknowledge
                $ma->setCarryFlag(false);
                break;

            case 0x04:
                // Read RTC Date (year, month, day in BCD)
                $this->readRtcDate($runtime);
                break;

            case 0x05:
                // Set RTC Date - just acknowledge
                $ma->setCarryFlag(false);
                break;

            default:
                // Unknown function - set CF to indicate error
                $ma->setCarryFlag(true);
                break;
        }
    }

    /**
     * AH=0x00: Read System Timer Counter
     *
     * Returns:
     *   AL = midnight flag (non-zero if midnight passed since last read)
     *   CX:DX = tick count since midnight
     */
    private function readSystemTimerCounter(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();

        // Read tick count from BDA (4 bytes, little-endian)
        $b0 = $ma->readRawByte(self::BDA_TIMER_TICK) ?? 0;
        $b1 = $ma->readRawByte(self::BDA_TIMER_TICK + 1) ?? 0;
        $b2 = $ma->readRawByte(self::BDA_TIMER_TICK + 2) ?? 0;
        $b3 = $ma->readRawByte(self::BDA_TIMER_TICK + 3) ?? 0;
        $tickCount = $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);

        // Read and clear midnight flag
        $midnightFlag = $ma->readRawByte(self::BDA_TIMER_OVERFLOW) ?? 0;
        $ma->writeBySize(self::BDA_TIMER_OVERFLOW, 0, 8);

        // Return values: AL = midnight flag (preserve AH)
        $ma->writeToLowBit(RegisterType::EAX, $midnightFlag & 0xFF);
        $ma->writeBySize(RegisterType::ECX, ($tickCount >> 16) & 0xFFFF, 16);
        $ma->writeBySize(RegisterType::EDX, $tickCount & 0xFFFF, 16);
        $ma->setCarryFlag(false);
    }

    /**
     * AH=0x01: Set System Timer Counter
     *
     * Input:
     *   CX:DX = new tick count
     */
    private function setSystemTimerCounter(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();

        $cx = $ma->fetch(RegisterType::ECX)->asByte() & 0xFFFF;
        $dx = $ma->fetch(RegisterType::EDX)->asByte() & 0xFFFF;
        $newTick = ($cx << 16) | $dx;

        // Write tick count to BDA
        $ma->writeBySize(self::BDA_TIMER_TICK, $newTick & 0xFF, 8);
        $ma->writeBySize(self::BDA_TIMER_TICK + 1, ($newTick >> 8) & 0xFF, 8);
        $ma->writeBySize(self::BDA_TIMER_TICK + 2, ($newTick >> 16) & 0xFF, 8);
        $ma->writeBySize(self::BDA_TIMER_TICK + 3, ($newTick >> 24) & 0xFF, 8);

        // Clear midnight flag
        $ma->writeBySize(self::BDA_TIMER_OVERFLOW, 0, 8);
    }

    /**
     * AH=0x02: Read RTC Time
     *
     * Returns:
     *   CF = 0 if successful
     *   CH = hours (BCD)
     *   CL = minutes (BCD)
     *   DH = seconds (BCD)
     *   DL = daylight savings time flag (0 = standard, 1 = DST)
     */
    private function readRtcTime(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();

        // Use real system time
        $now = time();
        $hours = (int) date('H', $now);
        $minutes = (int) date('i', $now);
        $seconds = (int) date('s', $now);

        // Convert to BCD
        $hoursBcd = $this->toBcd($hours);
        $minutesBcd = $this->toBcd($minutes);
        $secondsBcd = $this->toBcd($seconds);

        // Return values
        // CX = CH:CL = hours:minutes
        $ma->writeBySize(RegisterType::ECX, ($hoursBcd << 8) | $minutesBcd, 16);
        // DX = DH:DL = seconds:DST flag
        $ma->writeBySize(RegisterType::EDX, ($secondsBcd << 8) | 0x00, 16);
        $ma->setCarryFlag(false);

        $runtime->option()->logger()->debug(sprintf(
            'INT 0x1A AH=0x02: Read RTC Time %02d:%02d:%02d (BCD: CH=0x%02X CL=0x%02X DH=0x%02X)',
            $hours, $minutes, $seconds, $hoursBcd, $minutesBcd, $secondsBcd
        ));
    }

    /**
     * AH=0x04: Read RTC Date
     *
     * Returns:
     *   CF = 0 if successful
     *   CH = century (BCD, e.g., 0x20 for 2000s)
     *   CL = year within century (BCD, e.g., 0x24 for 2024)
     *   DH = month (BCD, 1-12)
     *   DL = day (BCD, 1-31)
     */
    private function readRtcDate(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();

        // Use real system time
        $now = time();
        $year = (int) date('Y', $now);
        $month = (int) date('n', $now);
        $day = (int) date('j', $now);

        // Convert to BCD
        $century = (int) ($year / 100);
        $yearInCentury = $year % 100;
        $centuryBcd = $this->toBcd($century);
        $yearBcd = $this->toBcd($yearInCentury);
        $monthBcd = $this->toBcd($month);
        $dayBcd = $this->toBcd($day);

        // Return values
        // CX = CH:CL = century:year
        $ma->writeBySize(RegisterType::ECX, ($centuryBcd << 8) | $yearBcd, 16);
        // DX = DH:DL = month:day
        $ma->writeBySize(RegisterType::EDX, ($monthBcd << 8) | $dayBcd, 16);
        $ma->setCarryFlag(false);

        $runtime->option()->logger()->debug(sprintf(
            'INT 0x1A AH=0x04: Read RTC Date %04d-%02d-%02d',
            $year, $month, $day
        ));
    }

    /**
     * Convert an integer (0-99) to BCD format.
     */
    private function toBcd(int $value): int
    {
        $value = $value % 100;
        return (($value / 10) << 4) | ($value % 10);
    }
}
