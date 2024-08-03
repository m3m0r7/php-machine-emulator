<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Frame;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface FrameSetInterface
{
    public function pos(): int;
}
