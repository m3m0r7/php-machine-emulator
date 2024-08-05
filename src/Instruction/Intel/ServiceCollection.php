<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Collection\ServiceCollectionInterface;
use PHPMachineEmulator\Instruction\Intel\Service\VideoMemoryService;

class ServiceCollection extends \PHPMachineEmulator\Collection\ServiceCollection implements ServiceCollectionInterface
{
    public function __construct()
    {
        $this->items[] = new VideoMemoryService();
    }
}
