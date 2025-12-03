<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\ExternalDevice;

interface DeviceInterface
{
    /**
     * Get the device type.
     */
    public function deviceType(): DeviceType;
}
