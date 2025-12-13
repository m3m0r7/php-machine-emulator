<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Memory;

class MemoryContext implements MemoryContextInterface
{
    public function __construct(
        protected int $maxMemory = 0x80000000,     // 2GB default
        protected int $initialMemory = 0x10000000, // 256MB default
        protected int $swapSize = 0x40000000,     // 1GB default
    ) {
    }

    public function maxMemory(): int
    {
        return $this->maxMemory;
    }

    public function initialMemory(): int
    {
        return $this->initialMemory;
    }

    public function swapSize(): int
    {
        return $this->swapSize;
    }
}
