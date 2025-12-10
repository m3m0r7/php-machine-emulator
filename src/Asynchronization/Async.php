<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

use parallel\Events as ParallelEvents;
use parallel\Events\Event as ParallelEvent;
use parallel\Runtime as ParallelRuntime;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Async implements AsyncInterface
{
    private readonly ParallelRuntime $parallelRuntime;

    /**
     * @var callable
     */
    private mixed $callback;

    public function __construct(callable $callback) {
        $this->callback = $callback;
        $this->parallelRuntime = new ParallelRuntime();
    }

    public function start(): AsyncResultInterface
    {
        return new AsyncResult($this->parallelRuntime->run(fn ($callback) => $callback(), [$this->callback]));
    }
}
