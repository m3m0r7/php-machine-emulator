<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\ExternalDevice;

use PHPMachineEmulator\Stream\StreamReaderInterface;

interface KeyboardContextInterface extends DeviceInterface
{
    /**
     * Get the keyboard stream reader.
     */
    public function stream(): StreamReaderInterface;
}
