<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\RegisterType;

interface MemoryAccessorInterface
{
    public function allocate(int $address): self;
    public function fetch(int|RegisterType $registerType): int|null;
    public function increment(int|RegisterType $registerType): self;
    public function write(int|RegisterType $registerType, int|null $value): self;
    public function shouldZeroFlag(): bool;
}
