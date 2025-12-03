<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\CPU;

use PHPMachineEmulator\ArchitectureType;

interface CPUContextInterface
{
    /**
     * Get the number of CPU cores.
     */
    public function cores(): int;

    /**
     * Get the CPU architecture type.
     */
    public function architectureType(): ArchitectureType;
}
