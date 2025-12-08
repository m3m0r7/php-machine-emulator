<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

use PHPMachineEmulator\Exception\StreamReaderException;

class ISO9660
{
    public const SECTOR_SIZE = 2048;
    public const SYSTEM_AREA_SECTORS = 16;
    private const BUFFER_SIZE = 8388608; // 8MB buffer

    // Volume Descriptor Types
    public const VD_TYPE_BOOT_RECORD = 0;
    public const VD_TYPE_PRIMARY = 1;
    public const VD_TYPE_SUPPLEMENTARY = 2;
    public const VD_TYPE_PARTITION = 3;
    public const VD_TYPE_TERMINATOR = 255;

    public const STANDARD_IDENTIFIER = 'CD001';

    private mixed $resource;
    private int $fileSize;
    private ?PrimaryVolumeDescriptor $primaryDescriptor = null;
    private ?BootRecord $bootRecord = null;

    // Buffer for read operations
    private string $buffer = '';
    private int $bufferStart = -1;

    public function __construct(private string $path)
    {
        $this->resource = fopen($path, 'rb');

        if ($this->resource === false) {
            throw new StreamReaderException('Cannot open ISO file');
        }

        $this->fileSize = filesize($path);

        if ($this->fileSize === false) {
            throw new StreamReaderException('Cannot get ISO file size');
        }

        $this->parseVolumeDescriptors();
    }

    public function __destruct()
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    private function parseVolumeDescriptors(): void
    {
        // Volume descriptors start at sector 16
        $sector = self::SYSTEM_AREA_SECTORS;

        while (true) {
            $this->seekSector($sector);
            $data = $this->readSector();

            if ($data === false || strlen($data) < 7) {
                break;
            }

            $type = ord($data[0]);
            $identifier = substr($data, 1, 5);
            $version = ord($data[6]);

            if ($identifier !== self::STANDARD_IDENTIFIER) {
                throw new StreamReaderException('Invalid ISO9660: Standard identifier not found');
            }

            if ($type === self::VD_TYPE_TERMINATOR) {
                break;
            }

            match ($type) {
                self::VD_TYPE_BOOT_RECORD => $this->bootRecord = new BootRecord($data),
                self::VD_TYPE_PRIMARY => $this->primaryDescriptor = new PrimaryVolumeDescriptor($data),
                default => null, // Skip other types
            };

            $sector++;
        }

        if ($this->primaryDescriptor === null) {
            throw new StreamReaderException('Invalid ISO9660: No primary volume descriptor found');
        }
    }

    public function primaryDescriptor(): ?PrimaryVolumeDescriptor
    {
        return $this->primaryDescriptor;
    }

    public function bootRecord(): ?BootRecord
    {
        return $this->bootRecord;
    }

    public function hasElTorito(): bool
    {
        return $this->bootRecord !== null && $this->bootRecord->isElTorito();
    }

    private int $currentSector = 0;

    public function readSector(): string|false
    {
        $offset = $this->currentSector * self::SECTOR_SIZE;
        $this->currentSector++;
        return $this->readAt($offset, self::SECTOR_SIZE);
    }

    public function readSectors(int $count): string|false
    {
        $offset = $this->currentSector * self::SECTOR_SIZE;
        $length = self::SECTOR_SIZE * $count;
        $this->currentSector += $count;
        return $this->readAt($offset, $length);
    }

    public function seekSector(int $sector): void
    {
        $this->currentSector = $sector;
    }

    public function readAt(int $offset, int $length): string|false
    {
        // Check if data is in buffer
        if ($this->bufferStart >= 0 &&
            $offset >= $this->bufferStart &&
            $offset + $length <= $this->bufferStart + strlen($this->buffer)) {
            return substr($this->buffer, $offset - $this->bufferStart, $length);
        }

        // Need to fill buffer
        $this->fillBuffer($offset);

        // Try again from buffer
        if ($offset >= $this->bufferStart &&
            $offset + $length <= $this->bufferStart + strlen($this->buffer)) {
            return substr($this->buffer, $offset - $this->bufferStart, $length);
        }

        // Fallback for large reads that don't fit in buffer
        fseek($this->resource, $offset, SEEK_SET);
        return fread($this->resource, $length);
    }

    private function fillBuffer(int $fromOffset): void
    {
        fseek($this->resource, $fromOffset, SEEK_SET);
        $data = fread($this->resource, self::BUFFER_SIZE);
        $this->buffer = $data !== false ? $data : '';
        $this->bufferStart = $fromOffset;
    }

    public function fileSize(): int
    {
        return $this->fileSize;
    }

    public function resource(): mixed
    {
        return $this->resource;
    }

    /**
     * Read a file from the ISO filesystem.
     *
     * @param string $path Path to the file (e.g., "/boot/isolinux/isolinux.cfg")
     * @return string|null File contents or null if not found
     */
    public function readFile(string $path): ?string
    {
        $entry = $this->findPath($path);
        if ($entry === null || $entry['isDir']) {
            return null;
        }

        // Read file data from ISO
        $offset = $entry['lba'] * self::SECTOR_SIZE;
        $data = $this->readAt($offset, $entry['size']);

        return $data !== false ? $data : null;
    }

    /**
     * Read directory entries from the ISO filesystem.
     *
     * @param string $path Path to the directory
     * @return array|null Array of entries or null if not found
     */
    public function readDirectory(string $path): ?array
    {
        if ($path === '' || $path === '/') {
            // Root directory
            $lba = $this->primaryDescriptor->rootDirectoryLBA;
            $size = $this->primaryDescriptor->rootDirectorySize;
        } else {
            $entry = $this->findPath($path);
            if ($entry === null || !$entry['isDir']) {
                return null;
            }
            $lba = $entry['lba'];
            $size = $entry['size'];
        }

        return $this->parseDirectory($lba, $size);
    }

    /**
     * Find a file or directory by path.
     *
     * @param string $path Path to find
     * @return array|null Entry info or null if not found
     */
    private function findPath(string $path): ?array
    {
        // Normalize path
        $path = trim($path, '/');
        if ($path === '') {
            return [
                'name' => '',
                'lba' => $this->primaryDescriptor->rootDirectoryLBA,
                'size' => $this->primaryDescriptor->rootDirectorySize,
                'isDir' => true,
            ];
        }

        $parts = explode('/', $path);
        $currentLba = $this->primaryDescriptor->rootDirectoryLBA;
        $currentSize = $this->primaryDescriptor->rootDirectorySize;

        foreach ($parts as $i => $part) {
            $isLast = ($i === count($parts) - 1);
            $entries = $this->parseDirectory($currentLba, $currentSize);

            if ($entries === null) {
                return null;
            }

            $found = false;
            foreach ($entries as $entry) {
                // Case-insensitive comparison for ISO9660
                if (strcasecmp($entry['name'], $part) === 0) {
                    if ($isLast) {
                        return $entry;
                    }
                    if (!$entry['isDir']) {
                        return null; // Not a directory but we need to go deeper
                    }
                    $currentLba = $entry['lba'];
                    $currentSize = $entry['size'];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return null;
            }
        }

        return null;
    }

    /**
     * Parse a directory at the given LBA.
     *
     * @return array Array of directory entries
     */
    private function parseDirectory(int $lba, int $size): array
    {
        $entries = [];
        $offset = $lba * self::SECTOR_SIZE;
        $data = $this->readAt($offset, $size);

        if ($data === false) {
            return [];
        }

        $pos = 0;
        while ($pos < strlen($data)) {
            // Directory record length
            $recordLen = ord($data[$pos]);

            if ($recordLen === 0) {
                // End of sector, skip to next sector boundary
                $nextSector = ((int)($pos / self::SECTOR_SIZE) + 1) * self::SECTOR_SIZE;
                if ($nextSector >= strlen($data)) {
                    break;
                }
                $pos = $nextSector;
                continue;
            }

            if ($pos + $recordLen > strlen($data)) {
                break;
            }

            $record = substr($data, $pos, $recordLen);
            $entry = $this->parseDirectoryRecord($record);

            if ($entry !== null && $entry['name'] !== '' && $entry['name'] !== '.' && $entry['name'] !== '..') {
                $entries[] = $entry;
            }

            $pos += $recordLen;
        }

        return $entries;
    }

    /**
     * Parse a single directory record.
     */
    private function parseDirectoryRecord(string $record): ?array
    {
        if (strlen($record) < 33) {
            return null;
        }

        // Extended Attribute Record Length (byte 1)
        // $extAttrLen = ord($record[1]);

        // Location of Extent (bytes 2-9, both-endian)
        $lba = unpack('V', substr($record, 2, 4))[1];

        // Data Length (bytes 10-17, both-endian)
        $size = unpack('V', substr($record, 10, 4))[1];

        // File Flags (byte 25)
        $flags = ord($record[25]);
        $isDir = ($flags & 0x02) !== 0;

        // File Identifier Length (byte 32)
        $nameLen = ord($record[32]);

        if ($nameLen === 0) {
            return null;
        }

        // File Identifier (bytes 33+)
        $name = substr($record, 33, $nameLen);

        // Handle . and .. entries
        if ($nameLen === 1 && ord($name[0]) === 0) {
            $name = '.';
        } elseif ($nameLen === 1 && ord($name[0]) === 1) {
            $name = '..';
        } else {
            // Remove version number (;1) and trailing dots
            $semicolon = strpos($name, ';');
            if ($semicolon !== false) {
                $name = substr($name, 0, $semicolon);
            }
            $name = rtrim($name, '.');
        }

        return [
            'name' => $name,
            'lba' => $lba,
            'size' => $size,
            'isDir' => $isDir,
        ];
    }
}
