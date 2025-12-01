<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\IO\InputInterface;
use PHPMachineEmulator\IO\IOInterface;
use PHPMachineEmulator\IO\OutputInterface;

class TestIO implements IOInterface
{
    public function input(): InputInterface
    {
        return new TestInput();
    }

    public function output(): OutputInterface
    {
        return new TestOutput();
    }

    public function errorOutput(): OutputInterface
    {
        return new TestOutput();
    }
}

class TestInput implements InputInterface
{
    public function key(): string
    {
        return '';
    }

    public function byte(): int
    {
        return 0;
    }
}

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
