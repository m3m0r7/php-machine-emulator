<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Ticker;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Interface for managing ticker registrations.
 */
interface TickerRegistryInterface
{
    /**
     * Register a ticker to be executed periodically.
     */
    public function register(TickerInterface $ticker): self;

    /**
     * Unregister a ticker.
     */
    public function unregister(TickerInterface $ticker): self;

    /**
     * Execute all registered tickers based on instruction count.
     */
    public function tick(RuntimeInterface $runtime): void;
}
