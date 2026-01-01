<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

/**
 * Interface for bootable streams that can be loaded into memory.
 */
interface BootableStreamInterface extends StreamReaderIsProxyableInterface
{
    /**
     * Get the load address where boot data should be placed in memory.
     */
    public function loadAddress(): int;

    /**
     * Get the load segment for CS register initialization.
     */
    public function loadSegment(): int;

    /**
     * Get the total size of boot data.
     */
    public function fileSize(): int;

    /**
     * Get the boot image metadata when available (e.g., El Torito images).
     */
    public function bootImage(): ?BootImageInterface;

    /**
     * Get the bootstrap size to load into memory.
     */
    public function bootLoadSize(): int;

    /**
     * Check if the boot image is in no-emulation mode.
     */
    public function isNoEmulation(): bool;

    /**
     * Read ISO sectors when backing media supports CD-ROM access.
     *
     * @return string|null Data read, or null when unsupported or on error.
     */
    public function readIsoSectors(int $lba, int $sectorCount): ?string;

    /**
     * Get the backing media size (e.g., ISO file size).
     */
    public function backingFileSize(): int;

    /**
     * Replace a byte range in the boot image when supported.
     */
    public function replaceRange(int $offset, string $data): void;
}
