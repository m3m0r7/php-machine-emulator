<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Pixel;

interface ColorInterface
{
    public function red(): int;
    public function blue(): int;
    public function green(): int;
}
