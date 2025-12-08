<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Memory;

interface MemoryContextInterface
{
    /**
     * Get the maximum memory size in bytes.
     */
    public function maxMemory(): int;

    /**
     * Get the initial memory size in bytes.
     */
    public function initialMemory(): int;

    /**
     * Get the swap size in bytes.
     */
    public function swapSize(): int;
}
