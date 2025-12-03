<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Storage;

class StorageInfo implements StorageInfoInterface
{
    public function __construct(
        protected int $size,
        protected string $type = 'hdd',
    ) {
    }

    public function size(): int
    {
        return $this->size;
    }

    public function type(): string
    {
        return $this->type;
    }
}
