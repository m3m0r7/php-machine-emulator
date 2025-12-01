<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Frame\FrameSetInterface;

class TestFrame implements FrameInterface
{
    private array $frameSets = [];

    public function append(FrameSetInterface $frameSet): FrameInterface
    {
        $this->frameSets[] = $frameSet;
        return $this;
    }

    public function pop(): FrameSetInterface|null
    {
        return array_pop($this->frameSets);
    }

    public function frameSets(): array
    {
        return $this->frameSets;
    }
}
