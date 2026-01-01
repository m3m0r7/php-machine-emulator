<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\IO\InputInterface;

class TestInput implements InputInterface
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
