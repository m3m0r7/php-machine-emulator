<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

class SectionEntry
{
    public readonly int $bootIndicator;
    public readonly int $mediaType;
    public readonly int $loadSegment;
    public readonly int $systemType;
    public readonly int $sectorCount;
    public readonly int $loadRBA;

    public function __construct(string $data)
    {
        $this->bootIndicator = ord($data[0]);
        $this->mediaType = ord($data[1]);
        $this->loadSegment = unpack('v', substr($data, 2, 2))[1];
        $this->systemType = ord($data[4]);
        $this->sectorCount = unpack('v', substr($data, 6, 2))[1];
        $this->loadRBA = unpack('V', substr($data, 8, 4))[1];
    }

    public function isBootable(): bool
    {
        return $this->bootIndicator === 0x88;
    }

    public function loadRBA(): int
    {
        return $this->loadRBA;
    }

    public function sectorCount(): int
    {
        return $this->sectorCount;
    }

    public function loadSegment(): int
    {
        return $this->loadSegment;
    }

    public function mediaType(): int
    {
        return $this->mediaType;
    }
}
