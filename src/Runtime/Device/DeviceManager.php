<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Device;

/**
 * Device manager implementation.
 * Manages device contexts (state holders) only - no tick processing.
 */
class DeviceManager implements DeviceManagerInterface
{
    /** @var array<string, DeviceContextInterface> */
    private array $devices = [];

    public function register(DeviceContextInterface $device): self
    {
        $this->devices[$device->name()] = $device;
        return $this;
    }

    public function get(string $name): ?DeviceContextInterface
    {
        return $this->devices[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->devices[$name]);
    }

    public function keyboards(): array
    {
        $keyboards = [];
        foreach ($this->devices as $device) {
            if ($device instanceof KeyboardContextInterface) {
                $keyboards[] = $device;
            }
        }
        return $keyboards;
    }

    public function all(): iterable
    {
        return $this->devices;
    }
}
