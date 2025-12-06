<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Concurrency;

interface PromiseCollectionInterface
{
    public function await(): array;
}
