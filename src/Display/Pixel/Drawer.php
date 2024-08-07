<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Pixel;

use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Drawer implements DrawerInterface
{
    public function __construct(protected ScreenWriterInterface $screenWriter)
    {}

    public function dot(ColorInterface $color): void
    {
        $dot = sprintf(
            "\033[38;2;%d;%d;%d;48;2;%d;%d;%d;1m",
            $color->red(),
            $color->green(),
            $color->blue(),
            $color->red(),
            $color->green(),
            $color->blue(),
        );

        $dot .= '|';

        // NOTE: Reset the ASCII sequence
        $dot .= "\033[0m";

        $this->screenWriter
            ->write($dot);
    }

    public function newline(): void
    {
        $this->screenWriter
            ->write("\n");
    }
}
