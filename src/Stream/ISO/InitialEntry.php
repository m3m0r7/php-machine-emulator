<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

class InitialEntry
{
    public readonly int $bootIndicator;
    public readonly int $mediaType;
    public readonly int $loadSegment;
    public readonly int $systemType;
    public readonly int $sectorCount;
    public readonly int $loadRBA;

    public function __construct(string $data)
    {
        // Boot Indicator (byte 0) - 0x88 = bootable, 0x00 = not bootable
        $this->bootIndicator = ord($data[0]);

        // Boot Media Type (byte 1)
        $this->mediaType = ord($data[1]);

        // Load Segment (bytes 2-3, little-endian)
        // If 0, use default 0x07C0
        $segment = unpack('v', substr($data, 2, 2))[1];
        $this->loadSegment = $segment === 0 ? 0x07C0 : $segment;

        // System Type (byte 4)
        $this->systemType = ord($data[4]);

        // Sector Count (bytes 6-7, little-endian)
        $this->sectorCount = unpack('v', substr($data, 6, 2))[1];

        // Load RBA (bytes 8-11, little-endian) - sector number to load from
        $this->loadRBA = unpack('V', substr($data, 8, 4))[1];
    }

    public function isBootable(): bool
    {
        return $this->bootIndicator === 0x88;
    }

    public function mediaTypeName(): string
    {
        return match ($this->mediaType) {
            ElTorito::MEDIA_NO_EMULATION => 'No Emulation',
            ElTorito::MEDIA_FLOPPY_1_2M => '1.2MB Floppy',
            ElTorito::MEDIA_FLOPPY_1_44M => '1.44MB Floppy',
            ElTorito::MEDIA_FLOPPY_2_88M => '2.88MB Floppy',
            ElTorito::MEDIA_HARD_DISK => 'Hard Disk',
            default => 'Unknown',
        };
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
