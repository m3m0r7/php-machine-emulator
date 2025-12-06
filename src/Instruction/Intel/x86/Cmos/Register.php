<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\Cmos;

/**
 * CMOS/RTC Register indices for MC146818.
 */
enum Register: int
{
    case SECONDS = 0x00;
    case SECONDS_ALARM = 0x01;
    case MINUTES = 0x02;
    case MINUTES_ALARM = 0x03;
    case HOURS = 0x04;
    case HOURS_ALARM = 0x05;
    case DAY_OF_WEEK = 0x06;
    case DAY_OF_MONTH = 0x07;
    case MONTH = 0x08;
    case YEAR = 0x09;
    case STATUS_A = 0x0A;
    case STATUS_B = 0x0B;
    case STATUS_C = 0x0C;
    case STATUS_D = 0x0D;
    case DIAGNOSTIC_STATUS = 0x0E;
    case SHUTDOWN_STATUS = 0x0F;
    case FLOPPY_DRIVE_TYPE = 0x10;
    case CENTURY = 0x32;
}
