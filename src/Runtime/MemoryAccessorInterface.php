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
    public function readControlRegister(int $index): int;
    public function writeControlRegister(int $index, int $value): void;

    public function shouldZeroFlag(): bool;
    public function shouldSignFlag(): bool;
    public function shouldOverflowFlag(): bool;
    public function shouldCarryFlag(): bool;
    public function shouldParityFlag(): bool;
    public function shouldAuxiliaryCarryFlag(): bool;
    public function shouldDirectionFlag(): bool;
    public function shouldInterruptFlag(): bool;

    public function setZeroFlag(bool $which): self;
    public function setSignFlag(bool $which): self;
    public function setOverflowFlag(bool $which): self;
    public function setParityFlag(bool $which): self;
    public function setAuxiliaryCarryFlag(bool $which): self;
    public function setDirectionFlag(bool $which): self;
    public function setInterruptFlag(bool $which): self;

    public function writeEfer(int $value): void;
    public function readEfer(): int;

    // Physical memory access
    public function readPhysical8(int $address): int;
    public function readPhysical16(int $address): int;
    public function readPhysical32(int $address): int;
    public function readPhysical64(int $address): int;
    public function writePhysical32(int $address, int $value): void;
    public function writePhysical64(int $address, int $value): void;

    // Linear address translation and memory access with paging
    public function translateLinear(int $linear, bool $isWrite, bool $isUser, bool $pagingEnabled, int $linearMask): array;
    public function readMemory8(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array;
    public function readMemory16(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array;
    public function readMemory32(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array;
    public function readMemory64(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array;
    public function writeMemory8(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int;
    public function writeMemory16(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int;
    public function writeMemory32(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int;
    public function writeMemory64(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int;
    public function writePhysical16(int $address, int $value): void;
}
