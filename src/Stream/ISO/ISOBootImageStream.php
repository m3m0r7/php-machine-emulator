<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Stream\BootableStreamInterface;
use PHPMachineEmulator\Stream\StreamReaderProxyInterface;

/**
 * Stream reader for ISO boot images.
 * Wraps an ISOStreamInterface and provides boot image data as a readable stream.
 */
class ISOBootImageStream implements BootableStreamInterface
{
    private BootImage $bootImage;
    private string $bootData;
    private int $fileSize;
    private int $offset = 0;
    private bool $isNoEmulation;

    public function __construct(private ISOStreamInterface $isoStream)
    {
        $bootImage = $isoStream->bootImage();

        if ($bootImage === null) {
            throw new StreamReaderException('No bootable image found in ISO');
        }

        $this->bootImage = $bootImage;
        $this->bootData = $bootImage->data();
        $this->isNoEmulation = $bootImage->isNoEmulation();

        // For No Emulation mode, BIOS only loads the first sector (2048 bytes)
        // The boot code is responsible for loading the rest via INT 13h
        if ($this->isNoEmulation) {
            $this->fileSize = min(strlen($this->bootData), ISO9660::SECTOR_SIZE);
        } else {
            $this->fileSize = strlen($this->bootData);
        }
    }

    public function bootImage(): BootImage
    {
        return $this->bootImage;
    }

    public function isoStream(): ISOStreamInterface
    {
        return $this->isoStream;
    }

    /**
     * Get the load address where boot data should be placed in memory.
     */
    public function loadAddress(): int
    {
        return $this->bootImage->loadAddress();
    }

    /**
     * Get the load segment for CS register initialization.
     */
    public function loadSegment(): int
    {
        return $this->bootImage->loadSegment();
    }

    public function char(): string
    {
        if ($this->offset >= $this->fileSize) {
            throw new StreamReaderException('Cannot read from stream: reached EOF');
        }

        $char = $this->bootData[$this->offset];
        $this->offset++;
        return $char;
    }

    public function byte(): int
    {
        return ord($this->char());
    }

    public function signedByte(): int
    {
        $byte = $this->byte();
        return $byte > 127 ? $byte - 256 : $byte;
    }

    public function short(): int
    {
        $low = $this->byte();
        $high = $this->byte();
        return $low | ($high << 8);
    }

    public function dword(): int
    {
        $b0 = $this->byte();
        $b1 = $this->byte();
        $b2 = $this->byte();
        $b3 = $this->byte();
        return $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $newOffset): self
    {
        if ($newOffset > $this->fileSize) {
            throw new StreamReaderException('Cannot set the offset beyond file size');
        }

        $this->offset = $newOffset;
        return $this;
    }

    public function isEOF(): bool
    {
        return $this->offset >= $this->fileSize;
    }

    public function proxy(): StreamReaderProxyInterface
    {
        return new ISOStreamProxy($this);
    }

    public function fileSize(): int
    {
        return $this->fileSize;
    }

    /**
     * Check if this is a No Emulation boot image (CD-ROM boot).
     */
    public function isNoEmulation(): bool
    {
        return $this->isNoEmulation;
    }

    /**
     * Read sectors from ISO using LBA addressing.
     * For No Emulation mode, LBA is in CD-ROM sectors (2048 bytes).
     *
     * @param int $lba LBA sector number (2048-byte sectors for CD-ROM)
     * @param int $sectorCount Number of sectors to read
     * @return string|null Data read, or null on error
     */
    public function readIsoSectors(int $lba, int $sectorCount): ?string
    {
        $iso = $this->isoStream->iso();
        $iso->seekSector($lba);
        $data = $iso->readSectors($sectorCount);
        return $data !== false ? $data : null;
    }

    /**
     * Get the underlying ISO9660 object for direct access.
     */
    public function iso(): ISO9660
    {
        return $this->isoStream->iso();
    }
}
