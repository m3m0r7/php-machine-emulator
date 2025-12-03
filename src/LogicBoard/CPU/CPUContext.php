<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\CPU;

use PHPMachineEmulator\ArchitectureType;

class CPUContext implements CPUContextInterface
{
    public function __construct(
        protected int $cores = 1,
        protected ArchitectureType $architectureType = ArchitectureType::Intel_x86,
    ) {
    }

    public function cores(): int
    {
        return $this->cores;
    }

    public function architectureType(): ArchitectureType
    {
        return $this->architectureType;
    }
}
