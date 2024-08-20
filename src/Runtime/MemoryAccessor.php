<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Exception\MemoryAccessorException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\BinaryInteger;

class MemoryAccessor implements MemoryAccessorInterface
{
    protected array $memory = [];
    protected bool $zeroFlag = false;
    protected bool $signFlag = false;
    protected bool $overflowFlag = false;
    protected bool $carryFlag = false;
    protected bool $parityFlag = false;
    protected bool $fireEvents = true;
    protected bool $enableUpdateFlags = false;

    public function __construct(protected RuntimeInterface $runtime, protected MemoryAccessorObserverCollectionInterface $memoryAccessorObserverCollection)
    {
    }

    public function allocate(int $address, int $size = 1, bool $safe = true): self
    {
        if ($safe && array_key_exists($address, $this->memory)) {
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

    public function tryToFetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface|null
    {
        $address = $this->asAddress($registerType);

        if (!array_key_exists($address, $this->memory)) {
            return null;
        }

        return new MemoryAccessorFetchResult($this->memory[$address]);
    }

    public function write16Bit(int|RegisterType $registerType, int|null $value): self
    {
        return $this->writeBySize($registerType, $value, 16);
    }

    public function writeBySize(int|RegisterType $registerType, int|null $value, int $size = 64): self
    {
        [$address, $previousValue] = $this
            ->processWrite(
                $registerType,
                BinaryInteger::asLittleEndian(
                    $value ?? 0,
                    $size,
                ),
            );

        $this->postProcessWhenWrote(
            $address,
            $previousValue,
            $value,
        );

        return $this;
    }

    public function writeToHighBit(int|RegisterType $registerType, int|null $value): self
    {
        [$address, $previousValue] = $this->processWrite(
            $registerType,
            (($this->fetch($registerType)->asLowBit() << 8) & 0b11111111_00000000) + ($value & 0b11111111),
        );

        $this->postProcessWhenWrote(
            $address,
            $previousValue,
            $value,
        );

        return $this;
    }

    public function writeToLowBit(int|RegisterType $registerType, int|null $value): self
    {
        [$address, $previousValue] = $this->processWrite(
            $registerType,
            (($value & 0b11111111) << 8) + ($this->fetch($registerType)->asHighBit() & 0b11111111),
        );

        $this->postProcessWhenWrote(
            $address,
            $previousValue,
            $value,
        );

        return $this;
    }

    protected function postProcessWhenWrote(int $address, int|null $previousValue, int|null $value): void
    {
        $wroteValue = ($value ?? 0) & 0b11111111;


        if ($this->enableUpdateFlags) {
            $this->updateFlags($value);
        }

        $this->processObservers(
            $address,
            $previousValue === null
                ? $previousValue
                : ($previousValue & 0b11111111),
            $wroteValue,
        );
    }

    public function enableUpdateFlags(bool $which): self
    {
        $this->enableUpdateFlags = $which;
        return $this;
    }


    public function updateFlags(int|null $value): self
    {
        $this->zeroFlag = $value === 0;
        $this->signFlag = $value !== null && $value < 0;
        $this->overflowFlag = $value !== null && $value > 0xFFFF;
        $this->parityFlag = $value !== null && substr_count(decbin($value & 0b11111111), '1') % 2 === 0;

        return $this;
    }

    public function setCarryFlag(bool $which): self
    {
        $this->carryFlag = $which;

        return $this;
    }

    public function add(int|RegisterType $registerType, int $value): self
    {
        $this
            ->write16Bit(
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
        $this->sub($registerType, 1);

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
        $fetchResult = $this->fetch($address)
            ->asBytesBySize();

        $this->writeBySize(
            $address,
            $fetchResult >> $size,
        );

        return new MemoryAccessorFetchResult(
            BinaryInteger::asLittleEndian(
                $fetchResult & ((1 << $size) - 1),
                $size,
            ),
        );
    }

    public function push(int|RegisterType $registerType, int|null $value, int $size = 16): self
    {
        $address = $this->asAddress($registerType);
        $fetchResult = $this->fetch($address)
            ->asBytesBySize();

        $value = $value & ((1 << $size) - 1);

        $this->writeBySize(
            $address,
            $storeValue = ($fetchResult << $size) + $value,
        );

        if ((($fetchResult << $size) + $value) !== ($actualStoredValue = $this->fetch($address)->asBytesBySize())) {
            throw new MemoryAccessorException(
                sprintf(
                    'Illegal to expect storing value %d but stored actually %d (original value: %d)',
                    $storeValue,
                    $actualStoredValue,
                    $value,
                )
            );
        }

        return $this;
    }

    private function processWrite(int|RegisterType $registerType, int|null $value): array
    {
        $address = $this->asAddress($registerType);
        $this->validateMemoryAddressWasAllocated($address);

        $previousValue = $this->memory[$address];

        $this->memory[$address] = $value;

        return [$address, $previousValue];
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
                    $previousValue,
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
