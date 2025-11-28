<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Ticker;

use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Pit;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Ticker for PIT (Programmable Interval Timer) operations.
 */
class PitTicker implements TickerInterface
{
    public function __construct(
        private readonly Pit $pit,
    ) {}

    public function tick(RuntimeInterface $runtime): void
    {
        $picState = $runtime->context()->cpu()->picState();
        $this->pit->tick(function () use ($picState) {
            $picState->raiseIrq0();
        });
    }

    public function interval(): int
    {
        return 10;
    }
}
