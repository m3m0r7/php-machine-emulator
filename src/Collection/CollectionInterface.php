<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Collection;

interface CollectionInterface extends \ArrayAccess, \IteratorAggregate
{
    public function verifyValue(mixed $value): bool;
}
