<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

class BootImage
{
    private string $imageData;
    private int $imageSize;

    public function __construct(
        private ISO9660 $iso,
        private int $loadRBA,
        private int $sectorCount,
        private int $mediaType,
        private int $loadSegment,
    ) {
        $this->loadImage();
    }

    private function loadImage(): void
    {
        // Calculate actual sector count based on media type
        $sectors = $this->sectorCount;

        if ($sectors === 0) {
            // Default sector counts based on media type
            $sectors = match ($this->mediaType) {
                ElTorito::MEDIA_FLOPPY_1_2M => 1200 * 1024 / ISO9660::SECTOR_SIZE,
                ElTorito::MEDIA_FLOPPY_1_44M => 1440 * 1024 / ISO9660::SECTOR_SIZE,
                ElTorito::MEDIA_FLOPPY_2_88M => 2880 * 1024 / ISO9660::SECTOR_SIZE,
                ElTorito::MEDIA_NO_EMULATION => 1, // Read at least one sector
                default => 1,
            };
        }

        // For no-emulation mode, sector count is in 512-byte virtual sectors
        // but ISO uses 2048-byte sectors
        if ($this->mediaType === ElTorito::MEDIA_NO_EMULATION) {
            // sectorCount is in 512-byte sectors, convert to bytes
            $this->imageSize = $this->sectorCount * 512;
            $isoSectors = (int) ceil($this->imageSize / ISO9660::SECTOR_SIZE);
        } else {
            $isoSectors = (int) $sectors;
            $this->imageSize = $isoSectors * ISO9660::SECTOR_SIZE;
        }

        $this->iso->seekSector($this->loadRBA);
        $this->imageData = $this->iso->readSectors($isoSectors) ?: '';

        // Trim to actual size for no-emulation mode
        if ($this->mediaType === ElTorito::MEDIA_NO_EMULATION && strlen($this->imageData) > $this->imageSize) {
            $this->imageData = substr($this->imageData, 0, $this->imageSize);
        }
    }

    public function data(): string
    {
        return $this->imageData;
    }

    public function size(): int
    {
        return strlen($this->imageData);
    }

    public function loadSegment(): int
    {
        return $this->loadSegment;
    }

    public function loadAddress(): int
    {
        return $this->loadSegment * 16;
    }

    public function mediaType(): int
    {
        return $this->mediaType;
    }

    public function isNoEmulation(): bool
    {
        return $this->mediaType === ElTorito::MEDIA_NO_EMULATION;
    }

    public function loadRBA(): int
    {
        return $this->loadRBA;
    }

    public function readAt(int $offset, int $length): string
    {
        if ($offset >= strlen($this->imageData)) {
            return str_repeat("\x00", $length);
        }

        $available = substr($this->imageData, $offset, $length);
        $remaining = $length - strlen($available);

        if ($remaining > 0) {
            $available .= str_repeat("\x00", $remaining);
        }

        return $available;
    }
}
