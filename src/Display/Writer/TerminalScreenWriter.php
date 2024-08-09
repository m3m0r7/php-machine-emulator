<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class TerminalScreenWriter implements ScreenWriterInterface
{
    public function __construct(protected RuntimeInterface $runtime, protected VideoTypeInfo $videoTypeInfo)
    {
    }

    public function write(string $value): void
    {
        $this->runtime
            ->option()
            ->IO()
            ->output()
            ->write($value);
    }

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

        $dot .= ' ';

        // NOTE: Reset the ASCII sequence
        $dot .= "\033[0m";

        $this->write($dot);
    }

    public function newline(): void
    {
        $this->write("\n");
    }
}
