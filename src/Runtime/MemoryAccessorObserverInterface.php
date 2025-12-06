<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\RegisterType;

interface MemoryAccessorObserverInterface
{
    /**
     * Return the address range this observer is interested in.
     * Returns null if the observer wants to see all writes (use sparingly).
     *
     * @return array{min: int, max: int}|null
     */
    public function addressRange(): ?array;

    public function shouldMatch(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): bool;
    public function observe(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): void;
}
