<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Runtime\RuntimeContextInterface;
use PHPMachineEmulator\Runtime\RuntimeCPUContextInterface;
use PHPMachineEmulator\Runtime\RuntimeScreenContextInterface;
use PHPMachineEmulator\Runtime\Device\DeviceManager;
use PHPMachineEmulator\Runtime\Device\DeviceManagerInterface;

class TestRuntimeContext implements RuntimeContextInterface
{
    private TestCPUContext $cpuContext;
    private TestScreenContext $screenContext;
    private DeviceManagerInterface $deviceManager;

    public function __construct()
    {
        $this->cpuContext = new TestCPUContext();
        $this->screenContext = new TestScreenContext();
        $this->deviceManager = new DeviceManager();
    }

    public function cpu(): TestCPUContext
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
