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
        // For floppy emulation modes, always load the full floppy image
        // regardless of sectorCount field (which is often incorrect/unused)
        $sectors = match ($this->mediaType) {
            ElTorito::MEDIA_FLOPPY_1_2M => (int) ceil(1200 * 1024 / ISO9660::SECTOR_SIZE),
            ElTorito::MEDIA_FLOPPY_1_44M => (int) ceil(1440 * 1024 / ISO9660::SECTOR_SIZE),
            ElTorito::MEDIA_FLOPPY_2_88M => (int) ceil(2880 * 1024 / ISO9660::SECTOR_SIZE),
            ElTorito::MEDIA_NO_EMULATION => $this->sectorCount > 0 ? $this->sectorCount : 1,
            default => $this->sectorCount > 0 ? $this->sectorCount : 1,
        };

        // Calculate actual image size based on media type
        $this->imageSize = match ($this->mediaType) {
            ElTorito::MEDIA_FLOPPY_1_2M => 1200 * 1024,      // 1.2MB
            ElTorito::MEDIA_FLOPPY_1_44M => 1440 * 1024,     // 1.44MB
            ElTorito::MEDIA_FLOPPY_2_88M => 2880 * 1024,     // 2.88MB
            ElTorito::MEDIA_NO_EMULATION => $this->sectorCount * 512,  // 512-byte virtual sectors
            default => $sectors * ISO9660::SECTOR_SIZE,
        };

        $isoSectors = (int) $sectors;

        $this->iso->seekSector($this->loadRBA);
        $this->imageData = $this->iso->readSectors($isoSectors) ?: '';

        // Trim to actual floppy/image size (ISO sectors are 2048 bytes, but floppy uses 512 bytes)
        if (strlen($this->imageData) > $this->imageSize) {
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
