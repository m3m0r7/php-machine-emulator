<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\RegisterNotFoundException;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\RegisterType;

enum BIOSInterrupt: int
{
    case VIDEO_INTERRUPT = 0x10;
    case DISK_INTERRUPT = 0x13;
    case KEYBOARD_INTERRUPT = 0x16;
}
