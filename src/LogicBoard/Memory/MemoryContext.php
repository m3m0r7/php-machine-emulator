<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Memory;

class MemoryContext implements MemoryContextInterface
{
    public function __construct(
        protected int $maxMemory = 0x1000000,      // 16MB default
        protected int $initialMemory = 0x200000,   // 2MB default
        protected int $swapSize = 0x10000000,      // 256MB default
        protected string $phpMemoryLimit = '1G',
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

    public function phpMemoryLimit(): string
    {
        return $this->phpMemoryLimit;
    }
}
