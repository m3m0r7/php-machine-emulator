<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Disk\DiskInterface;

class TestDisk implements DiskInterface
{
    public function offset(): int
    {
        return 0;
    }

    public function entrypointOffset(): int
    {
        return 0;
    }
}
