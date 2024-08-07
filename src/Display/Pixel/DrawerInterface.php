<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Pixel;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface DrawerInterface
{
    public function dot(ColorInterface $color): void;
    public function newline(): void;
}
