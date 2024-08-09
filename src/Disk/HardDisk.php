<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Disk;

class HardDisk implements DiskInterface
{
    public function __construct(
        protected int $driveNumber,
        protected int $offset,
        protected int $entrypointOffset,
    ) {}

    public function offset(): int
    {
        return $this->offset;
    }

    public function entrypointOffset(): int
    {
        return $this->entrypointOffset;
    }
}
