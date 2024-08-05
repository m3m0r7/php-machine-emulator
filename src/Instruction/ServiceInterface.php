<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface ServiceInterface
{
    public function initialize(RuntimeInterface $runtime): void;
}
