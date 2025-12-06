<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Concurrency;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use parallel\Runtime as ParallelRuntime;

class PromiseCollection implements PromiseCollectionInterface
{
    /**
     * @var PromiseResultInterface[]
     */
    private array $promises = [];

    /**
     * @param PromiseInterface[] $promises
     */
    public function __construct(array $promises = [])
    {
        foreach ($promises as $promise) {
            $this->promises[] = $promise->start();
        }
    }

    public function await(): array
    {
        return $this->process();
    }

    private function process(): array
    {
        $result = [];
        foreach ($this->promises as $key => $promise) {
            $result[$key] = $promise->await();
            unset($this->promises[$key]);
        }

        return $result;
    }
}
