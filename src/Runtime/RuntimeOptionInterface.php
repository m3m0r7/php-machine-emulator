<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

interface RuntimeOptionInterface
{
    public function entrypoint(): int;

    public function cpuContext(): RuntimeCPUContextInterface;
}
