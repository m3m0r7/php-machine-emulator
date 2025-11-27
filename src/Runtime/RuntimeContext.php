<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

class RuntimeContext implements RuntimeContextInterface
{
    public function __construct(
        private RuntimeCPUContextInterface $cpuContext,
        private RuntimeScreenContextInterface $screenContext,
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
}
