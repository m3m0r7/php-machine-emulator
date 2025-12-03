<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Storage;

interface StorageInfoInterface
{
    /**
     * Get the storage size in bytes.
     */
    public function size(): int;

    /**
     * Get the storage type (e.g., 'hdd', 'ssd').
     */
    public function type(): string;
}
