<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Disk;

class Bootloader implements DiskInterface
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
