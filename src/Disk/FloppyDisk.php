<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Disk;

/**
 * Floppy disk representation for boot images loaded from ISO files.
 */
class FloppyDisk implements DiskInterface
{
    public const DEFAULT_LOAD_SEGMENT = 0x07C0;
    public const DEFAULT_LOAD_ADDRESS = 0x7C00;
    public const SECTOR_SIZE = 512;

    public function __construct(
        private readonly int $driveNumber = 0x00,
        private readonly int $loadSegment = self::DEFAULT_LOAD_SEGMENT,
        private readonly int $loadAddress = self::DEFAULT_LOAD_ADDRESS,
        private readonly int $sectorCount = 1,
    ) {
    }

    public function offset(): int
    {
        return $this->loadAddress;
    }

    public function entrypointOffset(): int
    {
        return $this->loadAddress;
    }

    public function driveNumber(): int
    {
        return $this->driveNumber;
    }

    public function loadSegment(): int
    {
        return $this->loadSegment;
    }

    public function loadAddress(): int
    {
        return $this->loadAddress;
    }

    public function sectorCount(): int
    {
        return $this->sectorCount;
    }

    public function totalBytes(): int
    {
        return $this->sectorCount * self::SECTOR_SIZE;
    }
}
