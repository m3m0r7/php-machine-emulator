<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Instruction\Intel\Observer\VideoInitializerObserver;
use PHPMachineEmulator\Instruction\Intel\Observer\VideoMemoryObserver;

class MemoryAccessorObserverCollection extends \PHPMachineEmulator\Collection\MemoryAccessorObserverCollection implements MemoryAccessorObserverCollectionInterface
{
    public function __construct()
    {
        $this->items[] = new VideoInitializerObserver();
        $this->items[] = new VideoMemoryObserver();
    }
}
