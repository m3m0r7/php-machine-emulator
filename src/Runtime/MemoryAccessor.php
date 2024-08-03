<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Exception\MemoryAccessorException;

class MemoryAccessor implements MemoryAccessorInterface
{
    protected array $memory = [];
    protected bool $zeroFlag = false;

    public function allocate(int $address): self
    {
        if (array_key_exists($address, $this->memory)) {
            throw new MemoryAccessorException('Specified memory address was allocated');
        }

        $this->memory[$address] = null;

        return $this;
    }

    public function fetch(int $registerType): int|null
    {
        $this->validateMemoryAddressWasAllocated($registerType);

        return $this->memory[$registerType];
    }

    public function write(int $registerType, int|null $value): self
    {
        $this->validateMemoryAddressWasAllocated($registerType);

        $this->memory[$registerType] = $value;

        $this->zeroFlag = $value === 0;

        return $this;
    }

    public function shouldZeroFlag(): bool
    {
        return $this->zeroFlag;
    }

    private function validateMemoryAddressWasAllocated(int $address): void
    {
        if (!array_key_exists($address, $this->memory)) {
            throw new MemoryAccessorException('Specified memory address was allocated');
        }
    }
}
