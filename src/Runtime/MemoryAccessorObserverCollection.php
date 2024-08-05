<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Exception\MemoryAccessorObserverNotFoundException;
use PHPMachineEmulator\Instruction\RegisterType;
use Traversable;

class MemoryAccessorObserverCollection implements MemoryAccessorObserverCollectionInterface
{
    protected array $observers = [];

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->observers);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->observers[$offset]);
    }

    public function offsetGet(mixed $offset): MemoryAccessorObserverInterface
    {
        return $this->observers[$offset] ?? throw new MemoryAccessorObserverNotFoundException(
            sprintf(
                'The #%s does not exist',
                $offset,
            ),
        );
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset !== null) {
            throw new MemoryAccessorObserverNotFoundException(
                'The MemoryAccessorObserverCollections allows only enumerable offset',
            );
        }
        if (!($value instanceof MemoryAccessorObserverInterface)) {
            throw new MemoryAccessorObserverNotFoundException(
                'The MemoryAccessorObserverCollections allows only instantiated by MemoryAccessorObserverInterface',
            );
        }

        $this->observers[] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->observers[$offset]);

        // NOTE: Reset keys
        $this->observers = array_values($this->observers);
    }
}
