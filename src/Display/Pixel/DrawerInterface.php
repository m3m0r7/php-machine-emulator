<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Pixel;

interface DrawerInterface
{
    public function dot(ColorInterface $color): string;
}
