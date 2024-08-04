<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Exception\MemoryAccessorException;
use PHPMachineEmulator\Instruction\RegisterType;

interface MemoryAccessorFetchResultInterface
{
    public function asChar(): string;
    public function asByte(): int;
    public function valueOf(): int|null;
}
