<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Runtime\RuntimeCPUContextInterface;
use PHPMachineEmulator\Runtime\RuntimeOptionInterface;

class TestRuntimeOption implements RuntimeOptionInterface
{
    private RuntimeCPUContextInterface $cpuContext;
    private int $entrypoint;

    public function __construct(RuntimeCPUContextInterface $cpuContext, int $entrypoint = 0)
    {
        $this->cpuContext = $cpuContext;
        $this->entrypoint = $entrypoint;
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
