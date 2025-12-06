<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Concurrency;

interface PromiseResultInterface
{
    public function await(): mixed;
}
