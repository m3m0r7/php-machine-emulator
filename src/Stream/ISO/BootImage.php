<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

use PHPMachineEmulator\Stream\BootImageInterface;

class BootImage implements BootImageInterface
{
    private string $imageData;
    private int $imageSize;

    /** @var array<string, array{cluster: int, size: int, offset: int}> File index for FAT12 images */
    private array $fileIndex = [];

    private ?array $fat = null;

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
        // For No Emulation mode, check for Boot Info Table to get actual image size
        if ($this->mediaType === ElTorito::MEDIA_NO_EMULATION) {
            $this->loadNoEmulationImage();
            return;
        }

        // For floppy emulation modes, always load the full floppy image
        // regardless of sectorCount field (which is often incorrect/unused)
        $sectors = match ($this->mediaType) {
            ElTorito::MEDIA_FLOPPY_1_2M => (int) ceil(1200 * 1024 / ISO9660::SECTOR_SIZE),
            ElTorito::MEDIA_FLOPPY_1_44M => (int) ceil(1440 * 1024 / ISO9660::SECTOR_SIZE),
            ElTorito::MEDIA_FLOPPY_2_88M => (int) ceil(2880 * 1024 / ISO9660::SECTOR_SIZE),
            default => $this->sectorCount > 0 ? $this->sectorCount : 1,
        };

        // Calculate actual image size based on media type
        $this->imageSize = match ($this->mediaType) {
            ElTorito::MEDIA_FLOPPY_1_2M => 1200 * 1024,      // 1.2MB
            ElTorito::MEDIA_FLOPPY_1_44M => 1440 * 1024,     // 1.44MB
            ElTorito::MEDIA_FLOPPY_2_88M => 2880 * 1024,     // 2.88MB
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

    /**
     * Load No Emulation boot image.
     *
     * For ISOLINUX and similar bootloaders, we only load what the El Torito catalog
     * specifies (sectorCount * 512 bytes), typically just the first CD sector (2048 bytes).
     * The bootloader then uses INT 13h to load additional sectors to wherever it needs them.
     * This is important because bootloaders like ISOLINUX have specific memory layouts
     * and relocation schemes that don't match a simple contiguous load.
     */
    private function loadNoEmulationImage(): void
    {
        // For No Emulation, sectorCount is in 512-byte virtual sectors
        // Load only what the El Torito catalog specifies
        $this->imageSize = $this->sectorCount * 512;
        $sectorsNeeded = (int) ceil($this->imageSize / ISO9660::SECTOR_SIZE);

        // Ensure at least 1 CD sector is loaded
        if ($sectorsNeeded < 1) {
            $sectorsNeeded = 1;
        }

        $this->iso->seekSector($this->loadRBA);
        $this->imageData = $this->iso->readSectors($sectorsNeeded) ?: '';

        // Trim to exact size specified by El Torito
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

    /**
     * Get the sector count from El Torito boot catalog.
     * For No Emulation mode, this is in 512-byte virtual sectors.
     */
    public function catalogSectorCount(): int
    {
        return $this->sectorCount;
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

    public function replaceRange(int $offset, string $data): void
    {
        $len = strlen($data);
        if ($len === 0) {
            return;
        }
        if ($offset < 0 || ($offset + $len) > strlen($this->imageData)) {
            return;
        }

        $this->imageData = substr_replace($this->imageData, $data, $offset, $len);
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

    /**
     * Read file contents by path for FAT images (supports FAT12/16/32).
     */
    public function readFileByPath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return null;
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        if ($parts === []) {
            return null;
        }

        if (count($parts) === 1) {
            $name = strtoupper($parts[0]);
            if ($this->hasFile($name)) {
                return $this->readFile($name);
            }
        }

        $fat = $this->ensureFat();
        if ($fat === null) {
            return null;
        }

        $entry = $this->findPathEntry($parts, $fat);
        if ($entry === null || $entry['isDir']) {
            return null;
        }

        return $this->readClusterChainData($entry['cluster'], $entry['size'], $fat);
    }

    /**
     * Read directory entries by path for FAT images.
     *
     * @return array<int, array{name: string, isDir: bool, size: int, lba: int}>|null
     */
    public function readDirectory(string $path): ?array
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path);
        if ($path === '') {
            $path = '/';
        }

        $fat = $this->ensureFat();
        if ($fat === null) {
            return null;
        }

        if ($path === '/' || $path === '') {
            $entries = $this->readRootDirectoryEntries($fat);
        } else {
            $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
            if ($parts === []) {
                $entries = $this->readRootDirectoryEntries($fat);
            } else {
                $entry = $this->findPathEntry($parts, $fat);
                if ($entry === null || !$entry['isDir']) {
                    return null;
                }
                $entries = $this->readDirectoryEntriesFromCluster($entry['cluster'], $fat);
            }
        }

        $result = [];
        foreach ($entries as $entry) {
            $result[] = [
                'name' => $entry['name'],
                'isDir' => $entry['isDir'],
                'size' => $entry['size'],
                'lba' => 0,
            ];
        }

        return $result;
    }

    /**
     * @return array{bytesPerSector:int,sectorsPerCluster:int,reservedSectors:int,numFats:int,rootEntries:int,totalSectors:int,sectorsPerFat:int,rootDirSectors:int,fatType:int,fatOffset:int,rootDirOffset:int,dataOffset:int,rootCluster:int}|null
     */
    private function ensureFat(): ?array
    {
        if ($this->fat !== null) {
            return $this->fat;
        }

        $this->fat = $this->parseFatBootSector();
        return $this->fat;
    }

    /**
     * Parse FAT boot sector (BPB) to get layout info.
     *
     * @return array{bytesPerSector:int,sectorsPerCluster:int,reservedSectors:int,numFats:int,rootEntries:int,totalSectors:int,sectorsPerFat:int,rootDirSectors:int,fatType:int,fatOffset:int,rootDirOffset:int,dataOffset:int,rootCluster:int}|null
     */
    private function parseFatBootSector(): ?array
    {
        $img = $this->imageData;
        if (strlen($img) < 512) {
            return null;
        }

        $bytesPerSector = unpack('v', substr($img, 11, 2))[1] ?? 0;
        $sectorsPerCluster = ord($img[13] ?? "\x00");
        $reservedSectors = unpack('v', substr($img, 14, 2))[1] ?? 0;
        $numFats = ord($img[16] ?? "\x00");
        $rootEntries = unpack('v', substr($img, 17, 2))[1] ?? 0;
        $totalSectors16 = unpack('v', substr($img, 19, 2))[1] ?? 0;
        $sectorsPerFat16 = unpack('v', substr($img, 22, 2))[1] ?? 0;
        $totalSectors32 = unpack('V', substr($img, 32, 4))[1] ?? 0;
        $sectorsPerFat32 = unpack('V', substr($img, 36, 4))[1] ?? 0;
        $rootCluster = unpack('V', substr($img, 44, 4))[1] ?? 2;

        $totalSectors = $totalSectors16 !== 0 ? $totalSectors16 : $totalSectors32;
        $sectorsPerFat = $sectorsPerFat16 !== 0 ? $sectorsPerFat16 : $sectorsPerFat32;

        if ($bytesPerSector <= 0 || $sectorsPerCluster <= 0 || $totalSectors <= 0 || $sectorsPerFat <= 0 || $numFats <= 0) {
            return null;
        }

        $rootDirSectors = $rootEntries > 0
            ? intdiv($rootEntries * 32 + ($bytesPerSector - 1), $bytesPerSector)
            : 0;

        $dataSectors = $totalSectors - ($reservedSectors + ($numFats * $sectorsPerFat) + $rootDirSectors);
        if ($dataSectors < 0) {
            return null;
        }

        $clusterCount = intdiv($dataSectors, $sectorsPerCluster);
        if ($rootEntries === 0) {
            $fatType = 32;
        } elseif ($clusterCount < 4085) {
            $fatType = 12;
        } elseif ($clusterCount < 65525) {
            $fatType = 16;
        } else {
            $fatType = 32;
        }

        $fatOffset = $reservedSectors * $bytesPerSector;
        $rootDirOffset = ($reservedSectors + ($numFats * $sectorsPerFat)) * $bytesPerSector;
        $dataOffset = ($reservedSectors + ($numFats * $sectorsPerFat) + $rootDirSectors) * $bytesPerSector;

        return [
            'bytesPerSector' => $bytesPerSector,
            'sectorsPerCluster' => $sectorsPerCluster,
            'reservedSectors' => $reservedSectors,
            'numFats' => $numFats,
            'rootEntries' => $rootEntries,
            'totalSectors' => $totalSectors,
            'sectorsPerFat' => $sectorsPerFat,
            'rootDirSectors' => $rootDirSectors,
            'fatType' => $fatType,
            'fatOffset' => $fatOffset,
            'rootDirOffset' => $rootDirOffset,
            'dataOffset' => $dataOffset,
            'rootCluster' => $rootCluster,
        ];
    }

    /**
     * @param array{bytesPerSector:int,sectorsPerCluster:int,reservedSectors:int,numFats:int,rootEntries:int,totalSectors:int,sectorsPerFat:int,rootDirSectors:int,fatType:int,fatOffset:int,rootDirOffset:int,dataOffset:int,rootCluster:int} $fat
     * @return array<int, array{name:string,isDir:bool,size:int,cluster:int}>
     */
    private function readRootDirectoryEntries(array $fat): array
    {
        if ($fat['fatType'] === 32 && $fat['rootEntries'] === 0) {
            return $this->readDirectoryEntriesFromCluster($fat['rootCluster'], $fat);
        }

        $length = $fat['rootDirSectors'] * $fat['bytesPerSector'];
        if ($length <= 0) {
            return [];
        }

        $data = substr($this->imageData, $fat['rootDirOffset'], $length);
        return $this->parseDirectoryData($data, $fat);
    }

    /**
     * @param array{bytesPerSector:int,sectorsPerCluster:int,reservedSectors:int,numFats:int,rootEntries:int,totalSectors:int,sectorsPerFat:int,rootDirSectors:int,fatType:int,fatOffset:int,rootDirOffset:int,dataOffset:int,rootCluster:int} $fat
     * @return array<int, array{name:string,isDir:bool,size:int,cluster:int}>
     */
    private function readDirectoryEntriesFromCluster(int $cluster, array $fat): array
    {
        if ($cluster < 2) {
            return [];
        }

        $data = $this->readClusterChainData($cluster, null, $fat);
        return $this->parseDirectoryData($data, $fat);
    }

    /**
     * @param array{bytesPerSector:int,sectorsPerCluster:int,reservedSectors:int,numFats:int,rootEntries:int,totalSectors:int,sectorsPerFat:int,rootDirSectors:int,fatType:int,fatOffset:int,rootDirOffset:int,dataOffset:int,rootCluster:int} $fat
     * @return array<int, array{name:string,isDir:bool,size:int,cluster:int}>
     */
    private function parseDirectoryData(string $data, array $fat): array
    {
        $entries = [];
        $len = strlen($data);
        for ($offset = 0; $offset + 32 <= $len; $offset += 32) {
            $entry = substr($data, $offset, 32);
            $first = ord($entry[0]);
            if ($first === 0x00) {
                break;
            }
            if ($first === 0xE5) {
                continue;
            }
            $attr = ord($entry[11]);
            if ($attr === 0x0F || ($attr & 0x08) !== 0) {
                continue;
            }

            $name = rtrim(substr($entry, 0, 8));
            $ext = rtrim(substr($entry, 8, 3));
            if ($name === '') {
                continue;
            }

            $filename = $ext !== '' ? $name . '.' . $ext : $name;
            $filename = strtoupper($filename);
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            $clusterLow = ord($entry[26]) | (ord($entry[27]) << 8);
            $clusterHigh = ord($entry[20]) | (ord($entry[21]) << 8);
            $cluster = ($clusterHigh << 16) | $clusterLow;
            $size = ord($entry[28]) | (ord($entry[29]) << 8) | (ord($entry[30]) << 16) | (ord($entry[31]) << 24);

            $entries[] = [
                'name' => $filename,
                'isDir' => ($attr & 0x10) !== 0,
                'size' => $size,
                'cluster' => $cluster,
            ];
        }

        return $entries;
    }

    /**
     * @param string[] $parts
     * @param array{bytesPerSector:int,sectorsPerCluster:int,reservedSectors:int,numFats:int,rootEntries:int,totalSectors:int,sectorsPerFat:int,rootDirSectors:int,fatType:int,fatOffset:int,rootDirOffset:int,dataOffset:int,rootCluster:int} $fat
     * @return array{name:string,isDir:bool,size:int,cluster:int}|null
     */
    private function findPathEntry(array $parts, array $fat): ?array
    {
        $entries = $this->readRootDirectoryEntries($fat);
        $lastIndex = count($parts) - 1;

        foreach ($parts as $index => $part) {
            $target = strtoupper($part);
            $found = null;
            foreach ($entries as $entry) {
                if (strcasecmp($entry['name'], $target) === 0) {
                    $found = $entry;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            if ($index === $lastIndex) {
                return $found;
            }
            if (!$found['isDir']) {
                return null;
            }
            $entries = $this->readDirectoryEntriesFromCluster($found['cluster'], $fat);
        }

        return null;
    }

    /**
     * Read a cluster chain into a buffer.
     *
     * @param array{bytesPerSector:int,sectorsPerCluster:int,reservedSectors:int,numFats:int,rootEntries:int,totalSectors:int,sectorsPerFat:int,rootDirSectors:int,fatType:int,fatOffset:int,rootDirOffset:int,dataOffset:int,rootCluster:int} $fat
     */
    private function readClusterChainData(int $startCluster, ?int $size, array $fat): string
    {
        if ($startCluster < 2) {
            return '';
        }

        $limit = $size ?? PHP_INT_MAX;
        if ($limit <= 0) {
            return '';
        }

        $clusterSize = $fat['bytesPerSector'] * $fat['sectorsPerCluster'];
        $data = '';
        $cluster = $startCluster;

        while ($cluster >= 2 && strlen($data) < $limit) {
            $offset = $fat['dataOffset'] + ($cluster - 2) * $clusterSize;
            $data .= substr($this->imageData, $offset, $clusterSize);

            $next = $this->nextCluster($cluster, $fat);
            if ($next === 0 || $this->isEoc($next, $fat)) {
                break;
            }
            $cluster = $next;
        }

        return $size === null ? $data : substr($data, 0, $size);
    }

    /**
     * @param array{bytesPerSector:int,sectorsPerCluster:int,reservedSectors:int,numFats:int,rootEntries:int,totalSectors:int,sectorsPerFat:int,rootDirSectors:int,fatType:int,fatOffset:int,rootDirOffset:int,dataOffset:int,rootCluster:int} $fat
     */
    private function nextCluster(int $cluster, array $fat): int
    {
        $offset = $fat['fatOffset'];
        $img = $this->imageData;

        if ($fat['fatType'] === 12) {
            $fatOffset = $offset + intdiv($cluster * 3, 2);
            $byte0 = ord($img[$fatOffset] ?? chr(0));
            $byte1 = ord($img[$fatOffset + 1] ?? chr(0));
            $value = $byte0 | ($byte1 << 8);
            return ($cluster & 1) ? ($value >> 4) & 0x0FFF : $value & 0x0FFF;
        }

        if ($fat['fatType'] === 16) {
            $fatOffset = $offset + ($cluster * 2);
            $byte0 = ord($img[$fatOffset] ?? chr(0));
            $byte1 = ord($img[$fatOffset + 1] ?? chr(0));
            return $byte0 | ($byte1 << 8);
        }

        $fatOffset = $offset + ($cluster * 4);
        $byte0 = ord($img[$fatOffset] ?? chr(0));
        $byte1 = ord($img[$fatOffset + 1] ?? chr(0));
        $byte2 = ord($img[$fatOffset + 2] ?? chr(0));
        $byte3 = ord($img[$fatOffset + 3] ?? chr(0));
        $value = $byte0 | ($byte1 << 8) | ($byte2 << 16) | ($byte3 << 24);
        return $value & 0x0FFFFFFF;
    }

    /**
     * @param array{bytesPerSector:int,sectorsPerCluster:int,reservedSectors:int,numFats:int,rootEntries:int,totalSectors:int,sectorsPerFat:int,rootDirSectors:int,fatType:int,fatOffset:int,rootDirOffset:int,dataOffset:int,rootCluster:int} $fat
     */
    private function isEoc(int $cluster, array $fat): bool
    {
        if ($fat['fatType'] === 12) {
            return $cluster >= 0xFF8;
        }
        if ($fat['fatType'] === 16) {
            return $cluster >= 0xFFF8;
        }
        return $cluster >= 0x0FFFFFF8;
    }
}
