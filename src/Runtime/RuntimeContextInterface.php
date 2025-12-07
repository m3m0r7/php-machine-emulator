<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Runtime\Device\DeviceManagerInterface;

interface RuntimeContextInterface
{
    public function cpu(): RuntimeCPUContextInterface;

    public function screen(): RuntimeScreenContextInterface;

    public function devices(): DeviceManagerInterface;
}
