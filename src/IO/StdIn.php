<?php

declare(strict_types=1);

namespace PHPMachineEmulator\IO;

use PHPMachineEmulator\Exception\ReadKeyEndedException;
use PHPMachineEmulator\Stream\KeyboardReaderStream;
use PHPMachineEmulator\Stream\ResourceWriterStream;
use PHPMachineEmulator\Stream\StreamReaderInterface;

class StdIn implements InputInterface
{
    public function __construct(protected StreamReaderInterface $streamReader = new KeyboardReaderStream(STDIN))
    {

    }

    public function key(): string
    {
        return $this->streamReader->char();
    }

    public function byte(): int
    {
        return $this->streamReader->byte();
    }
}
