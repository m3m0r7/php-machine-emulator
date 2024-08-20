<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Frame;

use PHPMachineEmulator\Runtime\RuntimeInterface;

class Frame implements FrameInterface
{
    protected array $frameSets = [];

    public function __construct(protected RuntimeInterface $runtime)
    {
    }

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
