<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

interface AsyncInterface
{
    public function start(): AsyncResultInterface;
}
