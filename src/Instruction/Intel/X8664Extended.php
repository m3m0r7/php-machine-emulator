<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Extended x86-64 instruction list with PHP BIOS custom instructions.
 *
 * This class extends the standard x86-64 instruction set with special
 * custom instructions that are not part of the original Intel specification
 * but are needed for the PHP machine emulator implementation.
 *
 * Uses X86Extended as the underlying 32-bit instruction set to include
 * PHP BIOS custom instructions.
 */
class X8664Extended extends X8664
{
    public function __construct()
    {
        // Use X86Extended instead of x86 to include PHPBIOSCall
        $this->x86 = new X86Extended();
    }
}
