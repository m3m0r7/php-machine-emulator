<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

use PHPMachineEmulator\Exception\StreamReaderException;

class ISO9660
{
    public const SECTOR_SIZE = 2048;
    public const SYSTEM_AREA_SECTORS = 16;

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

    public function readSector(): string|false
    {
        return fread($this->resource, self::SECTOR_SIZE);
    }

    public function readSectors(int $count): string|false
    {
        return fread($this->resource, self::SECTOR_SIZE * $count);
    }

    public function seekSector(int $sector): void
    {
        fseek($this->resource, $sector * self::SECTOR_SIZE, SEEK_SET);
    }

    public function readAt(int $offset, int $length): string|false
    {
        fseek($this->resource, $offset, SEEK_SET);
        return fread($this->resource, $length);
    }

    public function fileSize(): int
    {
        return $this->fileSize;
    }

    public function resource(): mixed
    {
        return $this->resource;
    }
}
