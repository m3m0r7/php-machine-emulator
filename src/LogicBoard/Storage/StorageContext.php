<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Storage;

use PHPMachineEmulator\Exception\LogicBoardException;

class StorageContext implements StorageContextInterface
{
    /**
     * @var array<int, StorageInfoInterface>
     */
    protected array $storages = [];

    public function __construct(
        protected StorageInfoInterface $primaryStorage,
    ) {
        $this->storages[0] = $primaryStorage;
    }

    public function primary(): StorageInfoInterface
    {
        return $this->primaryStorage;
    }

    public function add(StorageInfoInterface $storage, int $index): static
    {
        if ($index < 0) {
            throw new LogicBoardException('Storage index must be non-negative');
        }

        $this->storages[$index] = $storage;
        return $this;
    }

    public function get(int $index): StorageInfoInterface
    {
        if (!$this->has($index)) {
            throw new LogicBoardException("Storage at index {$index} does not exist");
        }

        return $this->storages[$index];
    }

    public function has(int $index): bool
    {
        return isset($this->storages[$index]);
    }

    public function all(): array
    {
        return $this->storages;
    }
}
