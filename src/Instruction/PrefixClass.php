<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

/**
 * Instruction prefix classes for x86 instructions.
 */
enum PrefixClass
{
    /**
     * Operand size override prefix (0x66)
     * Toggles between 16-bit and 32-bit operand size.
     */
    case Operand;

    /**
     * Address size override prefix (0x67)
     * Toggles between 16-bit and 32-bit address size.
     */
    case Address;

    /**
     * Segment override prefixes (0x26/0x2E/0x36/0x3E/0x64/0x65)
     * ES, CS, SS, DS, FS, GS segment overrides.
     */
    case Segment;

    /**
     * LOCK prefix (0xF0)
     * Asserts LOCK# signal for atomic operations.
     */
    case Lock;
}
