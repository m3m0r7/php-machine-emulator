<?php

declare(strict_types=1);

namespace PHPMachineEmulator\IO;

use PHPMachineEmulator\Stream\ResourceWriterStream;
use PHPMachineEmulator\Stream\StreamWriterInterface;

class StdOut implements OutputInterface
{
    public function __construct(protected StreamWriterInterface $streamWriter = new ResourceWriterStream(STDOUT))
    {

    }

    public function write(string $value): self
    {
        $this->streamWriter->write($value);
        return $this;
    }
}
