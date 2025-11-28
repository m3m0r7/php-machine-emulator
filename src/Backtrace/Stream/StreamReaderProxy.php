<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Backtrace\Stream;

use PHPMachineEmulator\Backtrace\BacktraceableInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\StreamReaderIsProxyableInterface;
use PHPMachineEmulator\Stream\StreamReaderProxyInterface;

class StreamReaderProxy implements StreamReaderIsProxyableInterface, BacktraceableInterface
{
    protected int $index = 0;
    protected array $backtraces = [];

    public function __construct(protected RuntimeInterface $runtime, protected StreamReaderIsProxyableInterface $streamReader)
    {
        // NOTE: initialize first backtrace
        $this->backtraces[$this->index] = [];
    }

    public function offset(): int
    {
        return $this->streamReader->offset();
    }

    public function setOffset(int $newOffset): \PHPMachineEmulator\Stream\StreamReaderInterface
    {
        return $this->streamReader->setOffset($newOffset);
    }

    public function isEOF(): bool
    {
        return $this->streamReader->isEOF();
    }

    public function char(): string
    {
        $char = $this->streamReader->char();
        $this->backtraces[$this->index][] = ord($char);
        return $char;
    }

    public function byte(): int
    {
        $byte = $this->streamReader->byte();
        $this->backtraces[$this->index][] = $byte;
        return $byte;
    }

    public function signedByte(): int
    {
        $byte = $this->streamReader->signedByte();
        $this->backtraces[$this->index][] = $byte;
        return $byte;
    }

    public function short(): int
    {
        $short = $this->streamReader->short();
        $this->backtraces[$this->index][] = $short & 0xFF;
        $this->backtraces[$this->index][] = ($short >> 8) & 0xFF;
        return $short;
    }

    public function dword(): int
    {
        $dword = $this->streamReader->dword();
        $this->backtraces[$this->index][] = $dword & 0xFF;
        $this->backtraces[$this->index][] = ($dword >> 8) & 0xFF;
        $this->backtraces[$this->index][] = ($dword >> 16) & 0xFF;
        $this->backtraces[$this->index][] = ($dword >> 24) & 0xFF;
        return $dword;
    }

    public function proxy(): StreamReaderProxyInterface
    {
        return $this->streamReader->proxy();
    }

    public function next(): void
    {
        $this->index++;
        $this->backtraces[$this->index] = [];
    }

    public function last(): array
    {
        return $this->backtraces[$this->index];
    }
}
