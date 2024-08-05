<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Collection;

use PHPMachineEmulator\Runtime\MemoryAccessorObserverInterface;

class MemoryAccessorObserverCollection extends Collection implements MemoryAccessorObserverCollectionInterface
{
    public function verifyValue(mixed $value): bool
    {
        return $value instanceof MemoryAccessorObserverInterface;
    }
}
