<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * INT 12h - Get Conventional Memory Size
 *
 * Returns the amount of contiguous low memory in KB (0-640KB).
 * Output: AX = memory size in KB
 */
class MemorySize implements InterruptInterface
{
    public function process(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();

        // Get max memory size from runtime, convert bytes to KB
        // Conventional memory is capped at 640KB (0xA0000 bytes)
        $sizeInBytes = $runtime->memory()->logicalMaxMemorySize();
        $sizeInKB = (int) ($sizeInBytes / 1024);

        // Cap at 640KB (conventional memory limit)
        $conventionalKB = min($sizeInKB, 640);

        $ma->writeBySize(RegisterType::EAX, $conventionalKB, 16);
    }
}
