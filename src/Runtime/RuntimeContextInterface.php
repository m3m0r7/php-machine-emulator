<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

interface RuntimeContextInterface
{
    public function cpu(): RuntimeCPUContextInterface;

    public function screen(): RuntimeScreenContextInterface;
}
