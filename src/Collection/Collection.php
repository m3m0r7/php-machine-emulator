<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Collection;

use PHPMachineEmulator\Exception\CollectionException;
use Traversable;

class Collection implements CollectionInterface
{
    protected array $items = [];

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? throw new CollectionException(
            sprintf(
                'The #%s does not exist',
                $offset,
            ),
        );
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset !== null) {
            throw new CollectionException(
                sprintf('The %s allows only enumerable offset', get_class($this)),
            );
        }
        if (!$this->verifyValue($value)) {
            throw new CollectionException(
                sprintf(
                    'The %s does not passed to verify an item',
                    get_class($this)
                )
            );
        }

        $this->items[] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);

        // NOTE: Reset keys
        $this->items = array_values($this->items);
    }

    public function verifyValue(mixed $value): bool
    {
        return true;
    }
}
