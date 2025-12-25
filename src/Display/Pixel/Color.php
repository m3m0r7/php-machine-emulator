<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Pixel;

class Color implements ColorInterface
{
    public function __construct(protected int $red, protected int $green, protected int $blue)
    {
    }

    public function red(): int
    {
        return $this->red;
    }

    public function blue(): int
    {
        return $this->blue;
    }

    public function green(): int
    {
        return $this->green;
    }
}
