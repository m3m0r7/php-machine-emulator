<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Disk;

interface DiskInterface
{
    public function offset(): int;
    public function entrypointOffset(): int;
}
