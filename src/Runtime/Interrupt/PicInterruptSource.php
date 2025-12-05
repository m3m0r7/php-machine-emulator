<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Interrupt;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * PIC interrupt source.
 */
class PicInterruptSource implements InterruptSourceInterface
{
    public function isEnabled(RuntimeInterface $runtime): bool
    {
        // PIC is always enabled (unless APIC takes over, but that's handled by priority)
        return true;
    }

    public function pendingVector(RuntimeInterface $runtime): ?int
    {
        return $runtime->context()->cpu()->picState()->pendingVector();
    }
}
