<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\Traits\InstructionBaseTrait;

/**
 * Instructable trait for x86 instructions.
 *
 * This trait provides all necessary functionality for x86 instruction implementations.
 * It composes multiple specialized traits for:
 * - Register access (8/16/32-bit)
 * - Memory access and paging
 * - Segment handling
 * - Address calculation
 * - ModR/M decoding
 * - Flags operations
 * - I/O port operations
 * - Task switching
 *
 * For x86_64 instructions, use Instructable64 which extends this with 64-bit support.
 */
trait Instructable
{
    use InstructionBaseTrait;
}
