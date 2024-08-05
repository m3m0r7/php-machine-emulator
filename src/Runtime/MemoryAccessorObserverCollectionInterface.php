<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\RegisterType;

interface MemoryAccessorObserverCollectionInterface extends \ArrayAccess, \IteratorAggregate
{
}
