<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Runtime\RuntimeContextInterface;
use PHPMachineEmulator\Runtime\RuntimeCPUContextInterface;
use PHPMachineEmulator\Runtime\RuntimeScreenContextInterface;

class TestRuntimeContext implements RuntimeContextInterface
{
    private TestCPUContext $cpuContext;
    private TestScreenContext $screenContext;

    public function __construct()
    {
        $this->cpuContext = new TestCPUContext();
        $this->screenContext = new TestScreenContext();
    }

    public function cpu(): TestCPUContext
    {
        return $this->cpuContext;
    }

    public function screen(): RuntimeScreenContextInterface
    {
        return $this->screenContext;
    }
}
