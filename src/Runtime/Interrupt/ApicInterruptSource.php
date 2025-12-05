<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Interrupt;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * APIC interrupt source.
 */
class ApicInterruptSource implements InterruptSourceInterface
{
    public function isEnabled(RuntimeInterface $runtime): bool
    {
        return $runtime->context()->cpu()->apicState()->apicEnabled();
    }

    public function pendingVector(RuntimeInterface $runtime): ?int
    {
        return $runtime->context()->cpu()->apicState()->pendingVector();
    }
}
