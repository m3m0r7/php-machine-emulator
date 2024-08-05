<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

class RuntimeOption implements RuntimeOptionInterface
{
    public function __construct(protected int $entrypoint = 0x0000)
    {

    }

    public function entrypoint(): int
    {
        return $this->entrypoint;
    }
}
