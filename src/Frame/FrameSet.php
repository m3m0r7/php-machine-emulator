<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Frame;

use PHPMachineEmulator\Runtime\RuntimeInterface;

class FrameSet implements FrameSetInterface
{
    public function __construct(protected int $pos)
    {}

    public function pos(): int
    {
        return $this->pos;
    }
}
