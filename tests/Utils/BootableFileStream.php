<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Stream\BootImageInterface;
use PHPMachineEmulator\Stream\BootableStreamInterface;
use PHPMachineEmulator\Stream\GenericStream;
use PHPMachineEmulator\Stream\StreamReaderProxy;
use PHPMachineEmulator\Stream\StreamReaderProxyInterface;

/**
 * A bootable wrapper for raw binary files used in tests.
 * Provides BootableStreamInterface implementation.
 */
class BootableFileStream implements BootableStreamInterface
{
    use GenericStream;

    /** @var resource */
    protected mixed $resource;
    protected int $fileSize;

    public function __construct(
        protected string $path,
        private int $loadAddress = 0x0000,
        private int $loadSegment = 0x0000,
    ) {
        $this->resource = fopen($path, 'r');

        if ($this->resource === false) {
            throw new StreamReaderException('Cannot open specified path');
        }

        $this->fileSize = filesize($this->path);

        if ($this->fileSize === false) {
            throw new StreamReaderException('Cannot calculate file size');
        }
    }

    public function loadAddress(): int
    {
        return $this->loadAddress;
    }

    public function loadSegment(): int
    {
        return $this->loadSegment;
    }

    public function fileSize(): int
    {
        return $this->fileSize;
    }

    public function bootImage(): ?BootImageInterface
    {
        return null;
    }

    public function bootLoadSize(): int
    {
        return min($this->fileSize(), 512);
    }

    public function isNoEmulation(): bool
    {
        return false;
    }

    public function readIsoSectors(int $lba, int $sectorCount): ?string
    {
        return null;
    }

    public function backingFileSize(): int
    {
        return $this->fileSize();
    }

    public function replaceRange(int $offset, string $data): void
    {
    }

    public function proxy(): StreamReaderProxyInterface
    {
        $proxy = new self(
            $this->path,
            $this->loadAddress,
            $this->loadSegment,
        );
        $proxy->setOffset($this->offset());
        return new StreamReaderProxy($proxy);
    }
}
