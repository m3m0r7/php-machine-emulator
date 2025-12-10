<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

interface AsyncCollectionInterface
{
    public function await(): array;
}
