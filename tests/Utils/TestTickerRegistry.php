<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Runtime\Ticker\TickerInterface;
use PHPMachineEmulator\Runtime\Ticker\TickerRegistryInterface;

class TestTickerRegistry implements TickerRegistryInterface
{
    public function register(TickerInterface $ticker): self
    {
        return $this;
    }

    public function unregister(TickerInterface $ticker): self
    {
        return $this;
    }

    public function tick(RuntimeInterface $runtime): void
    {
        // No-op for testing
    }
}
