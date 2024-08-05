<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\RegisterType;

interface MemoryAccessorObserverInterface
{
    public function shouldMatch(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): bool;
    public function observe(RuntimeInterface $runtime, int $address, int|null $value): void;
}
