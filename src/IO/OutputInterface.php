<?php

declare(strict_types=1);

namespace PHPMachineEmulator\IO;

interface OutputInterface
{
    public function write(string $value): self;
}
