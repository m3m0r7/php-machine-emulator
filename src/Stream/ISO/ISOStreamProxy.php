<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

use PHPMachineEmulator\Stream\StreamReaderInterface;
use PHPMachineEmulator\Stream\StreamReaderProxyInterface;

class ISOStreamProxy implements StreamReaderProxyInterface
{
    private int $offset = 0;

    public function __construct(private ISOBootImageStream $stream)
    {
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $newOffset): StreamReaderInterface
    {
        $this->offset = $newOffset;
        return $this->stream;
    }

    public function isEOF(): bool
    {
        return $this->offset >= $this->stream->fileSize();
    }

    public function char(): string
    {
        // Save the original stream offset
        $originalOffset = $this->stream->offset();

        // Read at proxy's offset
        $this->stream->setOffset($this->offset);
        $char = $this->stream->char();
        $this->offset = $this->stream->offset();

        // Restore the original stream offset
        $this->stream->setOffset($originalOffset);

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

    public function read(int $length): string
    {
        return $this->readBytes($length);
    }

    public function readBytes(int $length): string
    {
        // Save the original stream offset
        $originalOffset = $this->stream->offset();

        // Read at proxy's offset
        $this->stream->setOffset($this->offset);
        $data = $this->stream->read($length);
        $this->offset = $this->stream->offset();

        // Restore the original stream offset
        $this->stream->setOffset($originalOffset);

        return $data;
    }

    public function isoStream(): ISOBootImageStream
    {
        return $this->stream;
    }

    /**
     * Read raw bytes from the underlying ISO file at given byte offset.
     * This is used for disk interrupt reads to access the full ISO.
     */
    public function readRawFromISO(int $byteOffset, int $length): string
    {
        $iso = $this->stream->iso();
        $data = $iso->readAt($byteOffset, $length);
        return $data !== false ? $data : '';
    }

    /**
     * Read raw sectors from the underlying ISO file.
     * Sector size is 2048 bytes for CD-ROM.
     */
    public function readCDSectors(int $sectorNumber, int $sectorCount): string
    {
        $iso = $this->stream->iso();
        $iso->seekSector($sectorNumber);
        $data = $iso->readSectors($sectorCount);
        return $data !== false ? $data : '';
    }

    /**
     * Get the file size of the underlying ISO.
     */
    public function isoFileSize(): int
    {
        return $this->stream->iso()->fileSize();
    }
}
