<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Instruction\Intel\Observer\VideoMemoryObserver;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverCollectionInterface;

class MemoryAccessorObserverCollection extends \PHPMachineEmulator\Runtime\MemoryAccessorObserverCollection implements MemoryAccessorObserverCollectionInterface
{
    public function __construct()
    {
        $this->observers[] = new VideoMemoryObserver();
    }
}
