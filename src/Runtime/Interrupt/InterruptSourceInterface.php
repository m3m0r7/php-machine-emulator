<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Interrupt;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Interface for interrupt sources (APIC, PIC, etc.)
 */
interface InterruptSourceInterface
{
    /**
     * Check if this interrupt source is enabled.
     */
    public function isEnabled(RuntimeInterface $runtime): bool;

    /**
     * Get the pending interrupt vector, or null if none pending.
     */
    public function pendingVector(RuntimeInterface $runtime): ?int;
}
