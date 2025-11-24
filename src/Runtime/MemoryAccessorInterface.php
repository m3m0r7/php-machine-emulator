<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\RegisterType;

interface MemoryAccessorInterface
{
    public function allocate(int $address, int $size = 1, bool $safe = true): self;
    public function fetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface;
    public function tryToFetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface|null;

    public function increment(int|RegisterType $registerType): self;
    public function add(int|RegisterType $registerType, int $value): self;
    public function sub(int|RegisterType $registerType, int $value): self;
    public function decrement(int|RegisterType $registerType): self;
    public function write16Bit(int|RegisterType $registerType, int|null $value): self;
    public function writeBySize(int|RegisterType $registerType, int|null $value, int $size = 64): self;
    public function writeToHighBit(int|RegisterType $registerType, int|null $value): self;
    public function writeToLowBit(int|RegisterType $registerType, int|null $value): self;

    public function updateFlags(int|null $value, int $size = 16): self;
    public function setCarryFlag(bool $which): self;
    public function pop(int|RegisterType $registerType, int $size = 16): MemoryAccessorFetchResultInterface;
    public function push(int|RegisterType $registerType, int|null $value, int $size = 16): self;

    public function enableUpdateFlags(bool $which): self;

    public function shouldZeroFlag(): bool;
    public function shouldSignFlag(): bool;
    public function shouldOverflowFlag(): bool;
    public function shouldCarryFlag(): bool;
    public function shouldParityFlag(): bool;
}
