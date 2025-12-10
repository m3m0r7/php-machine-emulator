<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

class SerializableAsyncMessage implements SerializableAsyncMessageInterface
{
    public function __construct(private array|int|string|bool|\stdClass|float|null $data)
    {}

    public function serialize(): ?string
    {
        return serialize($this->data);
    }

    public function unserialize(string $data)
    {
        return unserialize($data);
    }

    public function __serialize()
    {
        return serialize($this->data);
    }

    public function __unserialize($data)
    {
        return unserialize($data);
    }

    public function __toString(): string
    {
        return $this->serialize() ?? '';
    }
}
