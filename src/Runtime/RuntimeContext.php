<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Runtime\Device\DeviceManagerInterface;

class RuntimeContext implements RuntimeContextInterface
{
    public function __construct(
        private RuntimeCPUContextInterface $cpuContext,
        private RuntimeScreenContextInterface $screenContext,
        private DeviceManagerInterface $deviceManager,
    ) {
    }

    public function cpu(): RuntimeCPUContextInterface
    {
        return $this->cpuContext;
    }

    public function screen(): RuntimeScreenContextInterface
    {
        return $this->screenContext;
    }

    public function devices(): DeviceManagerInterface
    {
        return $this->deviceManager;
    }
}
