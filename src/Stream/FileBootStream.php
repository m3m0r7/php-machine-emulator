<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

class FileBootStream implements BootableStreamInterface
{
    public function __construct(
        protected FileStreamInterface $fileStream,
        protected int $loadAddress = 0x7C00,
        protected int $loadSegment = 0x0000,
    ) {
    }

    public function char(): string
    {
        return $this->fileStream->char();
    }

    public function byte(): int
    {
        return $this->fileStream->byte();
    }

    public function signedByte(): int
    {
        return $this->fileStream->signedByte();
    }

    public function short(): int
    {
        return $this->fileStream->short();
    }

    public function dword(): int
    {
        return $this->fileStream->dword();
    }

    public function offset(): int
    {
        return $this->fileStream->offset();
    }

    public function setOffset(int $newOffset): self
    {
        $this->fileStream->setOffset($newOffset);
        return $this;
    }

    public function isEOF(): bool
    {
        return $this->fileStream->isEOF();
    }

    public function proxy(): StreamReaderProxyInterface
    {
        return $this->fileStream->proxy();
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
        return $this->fileStream->fileSize();
    }
}
