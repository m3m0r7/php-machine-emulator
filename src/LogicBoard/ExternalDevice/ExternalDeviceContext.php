<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\ExternalDevice;

use PHPMachineEmulator\Exception\LogicBoardException;

class ExternalDeviceContext implements ExternalDeviceContextInterface
{
    /**
     * @var array<string, DeviceInterface>
     */
    protected array $devices = [];

    public function __construct()
    {
    }

    public function add(DeviceInterface $device, DeviceType $type): static
    {
        $this->devices[$type->value] = $device;
        return $this;
    }

    public function get(DeviceType $type): DeviceInterface
    {
        if (!$this->has($type)) {
            throw new LogicBoardException("Device of type {$type->value} does not exist");
        }

        return $this->devices[$type->value];
    }

    public function has(DeviceType $type): bool
    {
        return isset($this->devices[$type->value]);
    }

    public function all(): array
    {
        return $this->devices;
    }
}
