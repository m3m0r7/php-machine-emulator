<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

class RuntimeOption implements RuntimeOptionInterface
{
    protected RuntimeContextInterface $context;

    public function __construct(protected int $entrypoint = 0x0000, ?RuntimeContextInterface $context = null)
    {
        $this->context = $context ?? new RuntimeContext();
    }

    public function entrypoint(): int
    {
        return $this->entrypoint;
    }

    public function context(): RuntimeContextInterface
    {
        return $this->context;
    }
}
