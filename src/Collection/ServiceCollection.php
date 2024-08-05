<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Collection;

use PHPMachineEmulator\Instruction\ServiceInterface;

class ServiceCollection extends Collection implements ServiceCollectionInterface
{
    public function verifyValue(mixed $value): bool
    {
        return $value instanceof ServiceInterface;
    }
}
