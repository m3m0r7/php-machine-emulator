<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;
use PHPMachineEmulator\Runtime\RuntimeScreenContextInterface;

class TestScreenContext implements RuntimeScreenContextInterface
{
    private string $output = '';

    public function screenWriter(): ScreenWriterInterface
    {
        return new TestScreenWriter();
    }

    public function write(string $value): void
    {
        $this->output .= $value;
    }

    public function start(): void
    {
        // No-op
    }

    public function stop(): void
    {
        // No-op
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function clearOutput(): void
    {
        $this->output = '';
    }
}
