<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

class BootImage
{
    private string $imageData;
    private int $imageSize;

    /** @var array<string, array{cluster: int, size: int, offset: int}> File index for FAT12 images */
    private array $fileIndex = [];

    public function __construct(
        private ISO9660 $iso,
        private int $loadRBA,
        private int $sectorCount,
        private int $mediaType,
        private int $loadSegment,
    ) {
        $this->loadImage();
        $this->buildFileIndex();
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

    /**
     * Build file index for FAT12 floppy images.
     */
    private function buildFileIndex(): void
    {
        // Only for floppy media types
        if (!$this->isFloppyMedia()) {
            return;
        }

        $img = $this->imageData;
        if (strlen($img) < 512) {
            return;
        }

        // FAT12 standard parameters for 1.44MB floppy
        $bytesPerSector = 512;
        $sectorsPerCluster = 1;
        $reservedSectors = 1;
        $numberOfFats = 2;
        $sectorsPerFat = 9;
        $rootEntries = 224;

        $rootSectors = intdiv($rootEntries * 32 + ($bytesPerSector - 1), $bytesPerSector);
        $rootOffset = ($reservedSectors + $numberOfFats * $sectorsPerFat) * $bytesPerSector;
        $dataStartSector = $reservedSectors + $numberOfFats * $sectorsPerFat + $rootSectors;

        // Parse root directory entries
        for ($i = 0; $i < $rootEntries; $i++) {
            $entryOffset = $rootOffset + $i * 32;
            if ($entryOffset + 32 > strlen($img)) {
                break;
            }

            $entry = substr($img, $entryOffset, 32);
            $firstByte = ord($entry[0]);

            // End of directory
            if ($firstByte === 0x00) {
                break;
            }

            // Skip deleted entries and volume labels
            if ($firstByte === 0xE5 || (ord($entry[11]) & 0x08)) {
                continue;
            }

            // Parse filename (8.3 format)
            $name = strtoupper(rtrim(substr($entry, 0, 8)));
            $ext = rtrim(substr($entry, 8, 3));
            $filename = $ext !== '' ? $name . '.' . strtoupper($ext) : $name;

            // Parse cluster and size
            $startCluster = ord($entry[26]) | (ord($entry[27]) << 8);
            $fileSize = ord($entry[28]) | (ord($entry[29]) << 8) | (ord($entry[30]) << 16) | (ord($entry[31]) << 24);

            // Calculate byte offset in image
            $byteOffset = ($dataStartSector + ($startCluster - 2) * $sectorsPerCluster) * $bytesPerSector;

            $this->fileIndex[$filename] = [
                'cluster' => $startCluster,
                'size' => $fileSize,
                'offset' => $byteOffset,
            ];
        }
    }

    /**
     * Check if this is a floppy media type.
     */
    private function isFloppyMedia(): bool
    {
        return in_array($this->mediaType, [
            ElTorito::MEDIA_FLOPPY_1_2M,
            ElTorito::MEDIA_FLOPPY_1_44M,
            ElTorito::MEDIA_FLOPPY_2_88M,
        ], true);
    }

    /**
     * Get the file index (filename => info mapping).
     *
     * @return array<string, array{cluster: int, size: int, offset: int}>
     */
    public function getFileIndex(): array
    {
        return $this->fileIndex;
    }

    /**
     * Get file info by filename.
     *
     * @return array{cluster: int, size: int, offset: int}|null
     */
    public function getFileInfo(string $filename): ?array
    {
        return $this->fileIndex[strtoupper($filename)] ?? null;
    }

    /**
     * Read file contents by filename.
     */
    public function readFile(string $filename): ?string
    {
        $info = $this->getFileInfo($filename);
        if ($info === null) {
            return null;
        }

        return $this->readFileByClusterChain($info['cluster'], $info['size']);
    }

    /**
     * Read file contents by following the FAT12 cluster chain.
     */
    private function readFileByClusterChain(int $startCluster, int $size): string
    {
        $img = $this->imageData;

        // FAT12 parameters
        $bytesPerSector = 512;
        $sectorsPerCluster = 1;
        $reservedSectors = 1;
        $numberOfFats = 2;
        $sectorsPerFat = 9;
        $rootEntries = 224;

        $rootSectors = intdiv($rootEntries * 32 + ($bytesPerSector - 1), $bytesPerSector);
        $fatOffset = $reservedSectors * $bytesPerSector;
        $dataStartSector = $reservedSectors + $numberOfFats * $sectorsPerFat + $rootSectors;

        $data = '';
        $cluster = $startCluster;

        while ($cluster >= 2 && $cluster < 0xFF8 && strlen($data) < $size) {
            $lba = $dataStartSector + ($cluster - 2) * $sectorsPerCluster;
            $offset = $lba * $bytesPerSector;
            $data .= substr($img, $offset, $bytesPerSector * $sectorsPerCluster);

            // Get next cluster from FAT
            $cluster = $this->getFat12Entry($fatOffset, $cluster);
        }

        return substr($data, 0, $size);
    }

    /**
     * Get FAT12 entry for a cluster.
     */
    private function getFat12Entry(int $fatOffset, int $cluster): int
    {
        $offset = $fatOffset + intdiv($cluster * 3, 2);
        $byte0 = ord($this->imageData[$offset] ?? chr(0));
        $byte1 = ord($this->imageData[$offset + 1] ?? chr(0));
        $value = $byte0 | ($byte1 << 8);

        return ($cluster & 1) ? ($value >> 4) & 0x0FFF : $value & 0x0FFF;
    }

    /**
     * Check if a file exists in the image.
     */
    public function hasFile(string $filename): bool
    {
        return isset($this->fileIndex[strtoupper($filename)]);
    }

    /**
     * Get list of all filenames in the image.
     *
     * @return string[]
     */
    public function getFilenames(): array
    {
        return array_keys($this->fileIndex);
    }
}
