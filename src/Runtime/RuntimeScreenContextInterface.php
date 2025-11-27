<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;

interface RuntimeScreenContextInterface
{
    public function screenWriter(): ScreenWriterInterface;

    public function write(string $value): void;

    public function start(): void;

    public function stop(): void;
}
