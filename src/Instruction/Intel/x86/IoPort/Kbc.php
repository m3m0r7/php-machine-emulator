<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort;

/**
 * KBC (Keyboard Controller) 8042 I/O ports.
 *
 * The 8042 keyboard controller handles:
 * - PS/2 keyboard input
 * - PS/2 mouse input (auxiliary device)
 * - A20 gate control
 * - System reset
 */
enum Kbc: int
{
    case DATA = 0x60;
    case STATUS_COMMAND = 0x64;
}
