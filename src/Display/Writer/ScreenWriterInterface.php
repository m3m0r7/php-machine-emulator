<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\ColorInterface;

interface ScreenWriterInterface
{
    public function write(string $value): void;
    public function newline(): void;
    public function dot(ColorInterface $color): void;
}
