<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Media;

interface MediaContextInterface
{
    /**
     * Get the primary (bootable) media.
     */
    public function primary(): MediaInfoInterface;

    /**
     * Get BIOS drive counts for floppy/hard disk.
     *
     * @return array{int,int} [floppyCount, hardDriveCount]
     */
    public function bootDriveCounts(): array;

    /**
     * Get BIOS boot drive number for the primary media.
     */
    public function bootDriveNumber(): int;

    /**
     * Resolve the drive type for a BIOS drive number (DL).
     */
    public function driveTypeForBiosNumber(int $dl): ?DriveType;

    /**
     * Get the primary drive type.
     */
    public function primaryDriveType(): DriveType;

    /**
     * Check if any drive of the specified type exists.
     */
    public function hasDriveType(DriveType $driveType): bool;

    /**
     * Check if El Torito floppy emulation is active for the primary media.
     */
    public function isElToritoFloppyEmulation(): bool;

    /**
     * Resolve CHS geometry for a floppy based on media type and size.
     *
     * @return array{int,int,int} [cylinders, heads, sectors]
     */
    public function resolveFloppyGeometry(?int $mediaType, int $sizeBytes): array;

    /**
     * Resolve CHS geometry for a hard disk based on backing size.
     *
     * @return array{int,int,int} [cylinders, heads, sectors]
     */
    public function hardDiskGeometryFromSize(int $sizeBytes): array;

    /**
     * Add a media device at the specified index.
     *
     * @return static
     */
    public function add(MediaInfoInterface $media, int $index): static;

    /**
     * Get a media device by index.
     */
    public function get(int $index): MediaInfoInterface;

    /**
     * Check if a media device exists at the specified index.
     */
    public function has(int $index): bool;

    /**
     * Get all media devices.
     *
     * @return array<int, MediaInfoInterface>
     */
    public function all(): array;
}
