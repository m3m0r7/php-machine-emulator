<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display;

use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Cursor implements CursorInterface
{
    public function __construct(protected ScreenWriterInterface $screenWriter)
    {
    }

    public function reset(): void
    {
        $this->set(1, 1);
    }

    public function set(int $x, int $y): void
    {
        $this->screenWriter
            ->write(sprintf(
                "\033[%d;%dH",
                $x,
                $y
            ));
    }
}
