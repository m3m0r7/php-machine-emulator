<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort;

/**
 * CMOS/RTC (Real-Time Clock) MC146818 I/O ports.
 *
 * The CMOS contains:
 * - Real-time clock
 * - BIOS configuration data
 * - System status information
 */
enum Cmos: int
{
    case ADDRESS = 0x70;
    case DATA = 0x71;
}
