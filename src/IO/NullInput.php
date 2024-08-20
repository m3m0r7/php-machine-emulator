<?php

declare(strict_types=1);

namespace PHPMachineEmulator\IO;

class NullInput implements InputInterface
{
    public function key(): string
    {
        return '';
    }

    public function byte(): int
    {
        return 0;
    }
}
