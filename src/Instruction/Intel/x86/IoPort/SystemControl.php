<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort;

/**
 * System Control ports.
 *
 * Various system-level control ports for:
 * - Fast A20 gate control
 * - System reset
 */
enum SystemControl: int
{
    case PORT_A = 0x92;  // Fast A20 gate and fast reset
}
