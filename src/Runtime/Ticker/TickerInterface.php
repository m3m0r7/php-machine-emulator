<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Ticker;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Interface for ticker components that need periodic execution.
 */
interface TickerInterface
{
    /**
     * Execute a tick operation.
     */
    public function tick(RuntimeInterface $runtime): void;

    /**
     * Get the tick interval (execute every N instructions).
     */
    public function interval(): int;
}
