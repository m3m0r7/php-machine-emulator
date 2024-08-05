<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\RegisterType;

interface MemoryAccessorInterface
{
    public function allocate(int $address): self;
    public function fetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface;
    public function increment(int|RegisterType $registerType): self;
    public function write(int|RegisterType $registerType, int|null $value): self;
    public function updateFlags(int|null $value): self;
    public function pop(int|RegisterType $registerType, int $size = 32): MemoryAccessorFetchResultInterface;
    public function push(int|RegisterType $registerType, int|null $value, int $size = 32): self;

    public function shouldZeroFlag(): bool;
    public function shouldSignFlag(): bool;
    public function shouldOverflowFlag(): bool;
    public function shouldCarryFlag(): bool;
    public function shouldParityFlag(): bool;
}
