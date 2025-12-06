<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Concurrency;

interface PromiseInterface
{
    public function start(): PromiseResultInterface;
}
