<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Concurrency;

use parallel\Future as ParallelFuture;

class PromiseResult implements PromiseResultInterface
{
    public function __construct(private readonly ParallelFuture $parallelFuture) {
    }

    public function await(): mixed
    {
        return $this->parallelFuture->value();
    }
}
