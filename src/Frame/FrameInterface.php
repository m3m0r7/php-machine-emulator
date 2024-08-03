<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Frame;

interface FrameInterface
{
    public function append(FrameSetInterface $frameSet): self;
    public function pop(): FrameSetInterface|null;
}
