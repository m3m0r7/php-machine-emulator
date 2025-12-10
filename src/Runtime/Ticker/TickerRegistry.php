<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Ticker;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Registry for managing periodic tick operations.
 */
class TickerRegistry implements TickerRegistryInterface
{
    /** @var array<TickerInterface> */
    private array $tickers = [];

    public function register(TickerInterface $ticker): self
    {
        $this->tickers[spl_object_id($ticker)] = $ticker;
        return $this;
    }

    public function unregister(TickerInterface $ticker): self
    {
        unset($this->tickers[spl_object_id($ticker)]);
        return $this;
    }

    public function tick(RuntimeInterface $runtime): void
    {
        foreach ($this->tickers as $ticker) {
            if ($ticker->interval() === 0) {
                $ticker->tick($runtime);
                continue;
            }
        }
    }
}
