<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

class RuntimeOption implements RuntimeOptionInterface
{
    protected RuntimeCPUContextInterface $cpuContext;

    public function __construct(protected int $entrypoint = 0x0000, ?RuntimeCPUContextInterface $cpuContext = null)
    {
        $this->cpuContext = $cpuContext ?? new RuntimeCPUContext();
    }

    public function entrypoint(): int
    {
        return $this->entrypoint;
    }

    public function cpuContext(): RuntimeCPUContextInterface
    {
        return $this->cpuContext;
    }
}
