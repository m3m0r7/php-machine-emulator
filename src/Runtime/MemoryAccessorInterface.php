<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\RegisterType;

interface MemoryAccessorInterface
{
    public function allocate(int $address): self;
    public function fetch(int $registerType): int|null;
    public function write(int $registerType, int|null $value): self;
    public function shouldZeroFlag(): bool;
}
