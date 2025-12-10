<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use parallel\Runtime as ParallelRuntime;

class AsyncCollection implements AsyncCollectionInterface
{
    /**
     * @var AsyncResultInterface[]
     */
    private array $promises = [];

    /**
     * @param AsyncInterface[] $promises
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
