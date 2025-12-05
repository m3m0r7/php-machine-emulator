<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\RegisterNotFoundException;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\RegisterType;

enum BIOSInterrupt: int
{
    case TIMER_INTERRUPT = 0x08;
    case VIDEO_INTERRUPT = 0x10;
    case MEMORY_SIZE_INTERRUPT = 0x12;
    case DISK_INTERRUPT = 0x13;
    case SYSTEM_INTERRUPT = 0x15;
    case KEYBOARD_INTERRUPT = 0x16;
    case TIME_OF_DAY_INTERRUPT = 0x1A;
    case DOS_TERMINATE_INTERRUPT = 0x20;
    case DOS_INTERRUPT = 0x21;
}
