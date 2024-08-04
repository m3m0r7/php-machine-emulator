<?php

declare(strict_types=1);

namespace PHPMachineEmulator\IO;

use PHPMachineEmulator\Stream\ResourceWriterStream;
use PHPMachineEmulator\Stream\StreamWriterInterface;

class Buffer implements OutputInterface
{
    protected string $buffer = '';

    public function write(string $value): self
    {
        $this->buffer .= $value;
        return $this;
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }
}
