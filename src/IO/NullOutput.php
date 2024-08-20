<?php

declare(strict_types=1);

namespace PHPMachineEmulator\IO;

class NullOutput implements OutputInterface
{
    public function write(string $value): self
    {
        return $this;
    }
}
