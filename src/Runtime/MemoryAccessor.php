<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Exception\MemoryAccessorException;
use PHPMachineEmulator\Instruction\RegisterType;

class MemoryAccessor implements MemoryAccessorInterface
{
    protected array $memory = [];
    protected bool $zeroFlag = false;

    public function __construct(protected RuntimeInterface $runtime)
    {
    }

    public function allocate(int $address): self
    {
        if (array_key_exists($address, $this->memory)) {
            throw new MemoryAccessorException('Specified memory address was allocated');
        }

        $this->memory[$address] = null;

        return $this;
    }

    public function fetch(int|RegisterType $registerType): int|null
    {
        $address = $this->asAddress($registerType);
        $this->validateMemoryAddressWasAllocated($address);

        return $this->memory[$address];
    }

    public function write(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $this->validateMemoryAddressWasAllocated($address);

        $this->memory[$address] = $value;

        $this->zeroFlag = $value === 0;

        return $this;
    }

    public function increment(int|RegisterType $registerType): self
    {
        $this
            ->write(
                $registerType,
                $this->fetch($registerType) + 1
            );

        return $this;
    }

    public function shouldZeroFlag(): bool
    {
        return $this->zeroFlag;
    }

    public function asAddress(int|RegisterType $address): int
    {
        if ($address instanceof RegisterType) {
            return ($this->runtime->register())::addressBy($address);
        }
        return $address;
    }

    private function validateMemoryAddressWasAllocated(int $address): void
    {
        if (!array_key_exists($address, $this->memory)) {
            throw new MemoryAccessorException('Specified memory address was not allocated');
        }
    }
}
