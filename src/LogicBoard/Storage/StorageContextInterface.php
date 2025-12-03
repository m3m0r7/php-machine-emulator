<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Storage;

interface StorageContextInterface
{
    /**
     * Get the primary storage info.
     */
    public function primary(): StorageInfoInterface;

    /**
     * Add a storage device at the specified index.
     *
     * @return static
     */
    public function add(StorageInfoInterface $storage, int $index): static;

    /**
     * Get a storage device by index.
     */
    public function get(int $index): StorageInfoInterface;

    /**
     * Check if a storage device exists at the specified index.
     */
    public function has(int $index): bool;

    /**
     * Get all storage devices.
     *
     * @return array<int, StorageInfoInterface>
     */
    public function all(): array;
}
