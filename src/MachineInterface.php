<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface MachineInterface
{
    public function option(): OptionInterface;
    public function runtime(MachineType $useMachineType = MachineType::Intel_x86): RuntimeInterface;
}
