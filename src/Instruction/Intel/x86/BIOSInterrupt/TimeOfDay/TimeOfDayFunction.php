<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\TimeOfDay;

/**
 * INT 0x1A Time of Day BIOS Functions.
 *
 * Defines the function numbers (AH values) for INT 0x1A.
 */
enum TimeOfDayFunction: int
{
    /** Read System Timer Counter (returns tick count since midnight) */
    case READ_SYSTEM_TIMER = 0x00;

    /** Set System Timer Counter */
    case SET_SYSTEM_TIMER = 0x01;

    /** Read RTC Time (returns hours, minutes, seconds in BCD) */
    case READ_RTC_TIME = 0x02;

    /** Set RTC Time */
    case SET_RTC_TIME = 0x03;

    /** Read RTC Date (returns year, month, day in BCD) */
    case READ_RTC_DATE = 0x04;

    /** Set RTC Date */
    case SET_RTC_DATE = 0x05;
}
