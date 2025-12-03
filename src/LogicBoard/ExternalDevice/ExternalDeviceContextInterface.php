<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\ExternalDevice;

interface ExternalDeviceContextInterface
{
    /**
     * Add a device to the context.
     *
     * @return static
     */
    public function add(DeviceInterface $device, DeviceType $type): static;

    /**
     * Get a device by type.
     */
    public function get(DeviceType $type): DeviceInterface;

    /**
     * Check if a device of the specified type exists.
     */
    public function has(DeviceType $type): bool;

    /**
     * Get all devices.
     *
     * @return array<string, DeviceInterface>
     */
    public function all(): array;
}
