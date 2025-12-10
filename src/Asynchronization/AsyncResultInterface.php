<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

interface AsyncResultInterface
{
    public function start(): void;
}
