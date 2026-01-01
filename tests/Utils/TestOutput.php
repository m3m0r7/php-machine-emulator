<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\IO\OutputInterface;

class TestOutput implements OutputInterface
{
    private string $buffer = '';

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
