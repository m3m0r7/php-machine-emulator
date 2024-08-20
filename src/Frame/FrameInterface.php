<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Frame;

interface FrameInterface
{
    public function append(FrameSetInterface $frameSet): FrameInterface;
    public function pop(): FrameSetInterface|null;
    public function frameSets(): array;
}
