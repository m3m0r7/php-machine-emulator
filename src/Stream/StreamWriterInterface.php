<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

interface StreamWriterInterface
{
    public function write(string $value): self;
}
