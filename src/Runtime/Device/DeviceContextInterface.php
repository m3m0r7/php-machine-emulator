<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Device;

/**
 * Base interface for device context (state holder).
 * Device contexts hold state only - no processing logic.
 */
interface DeviceContextInterface
{
    /**
     * Get the unique name of this device.
     */
    public function name(): string;
}
