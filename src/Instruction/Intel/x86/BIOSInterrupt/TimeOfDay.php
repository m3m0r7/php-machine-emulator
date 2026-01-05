<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\TimeOfDay\BdaAddress;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\TimeOfDay\TimeOfDayFunction;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * INT 0x1A - Time of Day Services
 *
 * This BIOS interrupt provides access to the system timer tick counter
 * and the Real-Time Clock (RTC).
 */
class TimeOfDay implements InterruptInterface
{
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

        $function = TimeOfDayFunction::tryFrom($ah);

        match ($function) {
            TimeOfDayFunction::READ_SYSTEM_TIMER => $this->readSystemTimerCounter($runtime),
            TimeOfDayFunction::SET_SYSTEM_TIMER => $this->setSystemTimerCounter($runtime),
            TimeOfDayFunction::READ_RTC_TIME => $this->readRtcTime($runtime),
            TimeOfDayFunction::SET_RTC_TIME => $this->setRtcTime($runtime),
            TimeOfDayFunction::READ_RTC_DATE => $this->readRtcDate($runtime),
            TimeOfDayFunction::SET_RTC_DATE => $this->setRtcDate($runtime),
            default => $ma->setCarryFlag(true),
        };
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
        $timerTickAddr = BdaAddress::TIMER_TICK->value;
        $timerOverflowAddr = BdaAddress::TIMER_OVERFLOW->value;

        // Read tick count from BDA (4 bytes, little-endian)
        $b0 = $ma->readRawByte($timerTickAddr) ?? 0;
        $b1 = $ma->readRawByte($timerTickAddr + 1) ?? 0;
        $b2 = $ma->readRawByte($timerTickAddr + 2) ?? 0;
        $b3 = $ma->readRawByte($timerTickAddr + 3) ?? 0;
        $tickCount = $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);

        // Read and clear midnight flag
        $midnightFlag = $ma->readRawByte($timerOverflowAddr) ?? 0;
        $ma->writeBySize($timerOverflowAddr, 0, 8);

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
        $timerTickAddr = BdaAddress::TIMER_TICK->value;
        $timerOverflowAddr = BdaAddress::TIMER_OVERFLOW->value;

        $cx = $ma->fetch(RegisterType::ECX)->asByte() & 0xFFFF;
        $dx = $ma->fetch(RegisterType::EDX)->asByte() & 0xFFFF;
        $newTick = ($cx << 16) | $dx;

        // Write tick count to BDA
        $ma->writeBySize($timerTickAddr, $newTick & 0xFF, 8);
        $ma->writeBySize($timerTickAddr + 1, ($newTick >> 8) & 0xFF, 8);
        $ma->writeBySize($timerTickAddr + 2, ($newTick >> 16) & 0xFF, 8);
        $ma->writeBySize($timerTickAddr + 3, ($newTick >> 24) & 0xFF, 8);

        // Clear midnight flag
        $ma->writeBySize($timerOverflowAddr, 0, 8);
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
        $cmos = $runtime->context()->cpu()->cmos();

        $now = $cmos->getCurrentTime();
        $hours = $now->hour;
        $minutes = $now->minute;
        $seconds = $now->second;

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
            $hours,
            $minutes,
            $seconds,
            $hoursBcd,
            $minutesBcd,
            $secondsBcd
        ));
    }

    /**
     * AH=0x03: Set RTC Time
     *
     * Input:
     *   CH = hours (BCD)
     *   CL = minutes (BCD)
     *   DH = seconds (BCD)
     *   DL = daylight savings time flag (0 = standard, 1 = DST)
     */
    private function setRtcTime(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $cmos = $runtime->context()->cpu()->cmos();

        $cx = $ma->fetch(RegisterType::ECX)->asByte() & 0xFFFF;
        $dx = $ma->fetch(RegisterType::EDX)->asByte() & 0xFFFF;

        $hoursBcd = ($cx >> 8) & 0xFF;
        $minutesBcd = $cx & 0xFF;
        $secondsBcd = ($dx >> 8) & 0xFF;

        // Convert from BCD
        $hours = $this->fromBcd($hoursBcd);
        $minutes = $this->fromBcd($minutesBcd);
        $seconds = $this->fromBcd($secondsBcd);

        $cmos->setTime($hours, $minutes, $seconds);
        $ma->setCarryFlag(false);

        $runtime->option()->logger()->debug(sprintf(
            'INT 0x1A AH=0x03: Set RTC Time %02d:%02d:%02d',
            $hours,
            $minutes,
            $seconds
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
        $cmos = $runtime->context()->cpu()->cmos();

        $now = $cmos->getCurrentTime();
        $year = $now->year;
        $month = $now->month;
        $day = $now->day;

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
            $year,
            $month,
            $day
        ));
    }

    /**
     * AH=0x05: Set RTC Date
     *
     * Input:
     *   CH = century (BCD, e.g., 0x20 for 2000s)
     *   CL = year within century (BCD, e.g., 0x24 for 2024)
     *   DH = month (BCD, 1-12)
     *   DL = day (BCD, 1-31)
     */
    private function setRtcDate(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $cmos = $runtime->context()->cpu()->cmos();

        $cx = $ma->fetch(RegisterType::ECX)->asByte() & 0xFFFF;
        $dx = $ma->fetch(RegisterType::EDX)->asByte() & 0xFFFF;

        $centuryBcd = ($cx >> 8) & 0xFF;
        $yearBcd = $cx & 0xFF;
        $monthBcd = ($dx >> 8) & 0xFF;
        $dayBcd = $dx & 0xFF;

        // Convert from BCD
        $century = $this->fromBcd($centuryBcd);
        $yearInCentury = $this->fromBcd($yearBcd);
        $month = $this->fromBcd($monthBcd);
        $day = $this->fromBcd($dayBcd);

        $year = $century * 100 + $yearInCentury;

        $cmos->setDate($year, $month, $day);
        $ma->setCarryFlag(false);

        $runtime->option()->logger()->debug(sprintf(
            'INT 0x1A AH=0x05: Set RTC Date %04d-%02d-%02d',
            $year,
            $month,
            $day
        ));
    }

    /**
     * Convert an integer (0-99) to BCD format.
     */
    private function toBcd(int $value): int
    {
        $value = $value % 100;
        return (((int) ($value / 10)) << 4) | ($value % 10);
    }

    /**
     * Convert a BCD value to integer.
     */
    private function fromBcd(int $bcd): int
    {
        return (($bcd >> 4) & 0x0F) * 10 + ($bcd & 0x0F);
    }
}
