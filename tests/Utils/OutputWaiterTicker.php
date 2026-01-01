<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Runtime\Ticker\TickerInterface;

final class OutputWaiterTicker implements TickerInterface
{
    private float $startTime;

    /**
     * @param string[] $needles
     */
    public function __construct(
        private Buffer $output,
        private array $needles,
        private float $timeoutSeconds,
    ) {
        $this->startTime = microtime(true);
    }

    public function tick(RuntimeInterface $runtime): void
    {
        $buffer = $this->output->getBuffer();
        $allMatched = true;
        foreach ($this->needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (!str_contains($buffer, $needle)) {
                $allMatched = false;
                break;
            }
        }

        if ($allMatched) {
            throw new OutputWaiterMatchedException();
        }

        $elapsed = microtime(true) - $this->startTime;
        if ($elapsed >= $this->timeoutSeconds) {
            throw new OutputWaiterTimeoutException($elapsed);
        }
    }

    public function interval(): int
    {
        return 1;
    }
}
