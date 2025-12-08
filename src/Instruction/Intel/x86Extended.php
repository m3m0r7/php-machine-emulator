<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\BIOS\PHPBIOSCall;

/**
 * Extended x86 instruction list with PHP BIOS custom instructions.
 *
 * This class extends the standard x86 instruction set with special
 * custom instructions that are not part of the original Intel specification
 * but are needed for the PHP machine emulator implementation.
 *
 * Custom instructions:
 * - PHPBIOSCall (0F FF xx): Direct PHP BIOS service invocation
 */
class x86Extended extends x86
{
    protected function instructionListClasses(): array
    {
        return [
            ...parent::instructionListClasses(),
            PHPBIOSCall::class,
        ];
    }
}
