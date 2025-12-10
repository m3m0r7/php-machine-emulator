<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

use parallel\Future as ParallelFuture;

class AsyncResult implements AsyncResultInterface
{
    public function __construct(private readonly ParallelFuture $parallelFuture) {
    }

    public function await(): mixed
    {
        return $this->parallelFuture->value();
    }
}
