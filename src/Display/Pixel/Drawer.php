<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Pixel;

class Drawer implements DrawerInterface
{
    public function dot(ColorInterface $color): string
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

        return $dot;
    }
}
