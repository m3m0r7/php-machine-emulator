<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Exception\MemoryAccessorException;
use PHPMachineEmulator\Instruction\RegisterType;

class MemoryAccessor implements MemoryAccessorInterface
{
    protected array $memory = [];
    protected bool $zeroFlag = false;
    protected bool $signFlag = false;
    protected bool $overflowFlag = false;
    protected bool $carryFlag = false;
    protected bool $parityFlag = false;

    public function __construct(protected RuntimeInterface $runtime, protected MemoryAccessorObserverCollectionInterface $memoryAccessorObserverCollection)
    {
    }

    public function allocate(int $address, int $size = 1): self
    {
        if (array_key_exists($address, $this->memory)) {
            throw new MemoryAccessorException('Specified memory address was allocated');
        }

        for ($i = 0; $i < $size; $i++) {
            $this->memory[$address + $i] = null;
        }

        return $this;
    }

    public function fetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface
    {
        $address = $this->asAddress($registerType);
        $this->validateMemoryAddressWasAllocated($address);

        return new MemoryAccessorFetchResult($this->memory[$address]);
    }

    public function write(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $this->validateMemoryAddressWasAllocated($address);

        $previousValue = $this->memory[$address];

        $this->memory[$address] = $value;
        $this->updateFlags($value);
        $this->processObservers($address, $previousValue, $value);

        return $this;
    }

    public function writeToHighBit(int|RegisterType $registerType, int|null $value): self
    {
        $this->write(
            $registerType,
            // Write with Little endian
            (($this->fetch($registerType)->asByte() << 8) & 0b11111111_00000000) + ($value & 0b11111111),
        );

        return $this;
    }

    public function writeToLowBit(int|RegisterType $registerType, int|null $value): self
    {
        $this->write(
            $registerType,
            // Write with Little endian
            (($value << 8) & 0b11111111_00000000) + ($this->fetch($registerType)->asByte() & 0b11111111),
        );

        return $this;
    }

    public function updateFlags(int|null $value): self
    {
        $this->zeroFlag = $value === 0;
        $this->signFlag = $value !== null && $value < 0;
        $this->overflowFlag = $value !== null && $value > 0xFFFF;

        // TODO: implement here
        $this->carryFlag = false;

        $this->parityFlag = $value !== null && substr_count(decbin($value & 0b11111111), '1') % 2 === 0;

        return $this;
    }

    public function add(int|RegisterType $registerType, int $value): self
    {
        $this
            ->write(
                $registerType,
                $this->fetch($registerType)->asByte() + $value
            );

        return $this;
    }

    public function sub(int|RegisterType $registerType, int $value): self
    {
        $this->add($registerType, -$value);

        return $this;
    }

    public function increment(int|RegisterType $registerType): self
    {
        $this->add($registerType, 1);

        return $this;
    }

    public function decrement(int|RegisterType $registerType): self
    {
        $this->add($registerType, -1);

        return $this;
    }

    public function shouldZeroFlag(): bool
    {
        return $this->zeroFlag;
    }

    public function shouldSignFlag(): bool
    {
        return $this->signFlag;
    }

    public function shouldOverflowFlag(): bool
    {
        return $this->overflowFlag;
    }

    public function shouldCarryFlag(): bool
    {
        return $this->carryFlag;
    }

    public function shouldParityFlag(): bool
    {
        return $this->parityFlag;
    }

    protected function asAddress(int|RegisterType $address): int
    {
        if ($address instanceof RegisterType) {
            return ($this->runtime->register())::addressBy($address);
        }
        return $address;
    }

    public function pop(int|RegisterType $registerType, int $size = 16): MemoryAccessorFetchResultInterface
    {
        $address = $this->asAddress($registerType);
        $fetchResult = $this->fetch($address)->asByte();

        $this->write(
            $address,
            $fetchResult >> $size,
        );

        return new MemoryAccessorFetchResult($fetchResult & ((1 << $size) - 1));
    }

    public function push(int|RegisterType $registerType, int|null $value, int $size = 16): self
    {
        $address = $this->asAddress($registerType);
        $fetchResult = $this->fetch($address)->asByte();

        $this->write(
            $address,
            ($fetchResult << $size) + ($value & ((1 << $size) - 1)),
        );

        return $this;
    }

    private function processObservers(int $address, int|null $previousValue, int|null $nextValue): void
    {
        foreach ($this->memoryAccessorObserverCollection as $memoryAccessorObserverCollection) {
            assert($memoryAccessorObserverCollection instanceof MemoryAccessorObserverInterface);

            if (!$memoryAccessorObserverCollection->shouldMatch($this->runtime, $address, $previousValue, $nextValue)) {
                continue;
            }

            $memoryAccessorObserverCollection
                ->observe(
                    $this->runtime,
                    $address,
                    $nextValue,
                );
        }
    }

    private function validateMemoryAddressWasAllocated(int $address): void
    {
        if (array_key_exists($address, $this->memory)) {
            return;
        }

        throw new MemoryAccessorException(
            sprintf(
                'Specified memory address was not allocated: 0x%04X',
                $address,
            ),
        );
    }
}
