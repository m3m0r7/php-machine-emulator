<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Ticker;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Ticker for APIC (Advanced Programmable Interrupt Controller) timer operations.
 */
class ApicTicker implements TickerInterface
{
    public function tick(RuntimeInterface $runtime): void
    {
        $apic = $runtime->context()->cpu()->apicState();
        $apic->tick(null);
    }

    public function interval(): int
    {
        return 10;
    }
}
