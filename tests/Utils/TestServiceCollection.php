<?php

declare(strict_types=1);

namespace Tests\Utils;

use ArrayIterator;
use PHPMachineEmulator\Collection\ServiceCollectionInterface;
use PHPMachineEmulator\Instruction\ServiceInterface;
use Traversable;

class TestServiceCollection implements ServiceCollectionInterface
{
    private array $services = [];

    public function verifyValue(mixed $value): bool
    {
        return $value instanceof ServiceInterface;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->services[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->services[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->services[] = $value;
        } else {
            $this->services[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->services[$offset]);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->services);
    }
}
