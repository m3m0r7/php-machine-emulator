<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Device;

/**
 * Interface for device manager.
 * Manages device contexts (state holders) only - no tick processing.
 */
interface DeviceManagerInterface
{
    /**
     * Register a device context.
     */
    public function register(DeviceContextInterface $device): self;

    /**
     * Get a device context by name.
     */
    public function get(string $name): ?DeviceContextInterface;

    /**
     * Check if a device is registered.
     */
    public function has(string $name): bool;

    /**
     * Get all keyboard contexts.
     *
     * @return array<KeyboardContextInterface>
     */
    public function keyboards(): array;

    /**
     * Get all registered devices.
     *
     * @return iterable<DeviceContextInterface>
     */
    public function all(): iterable;
}
