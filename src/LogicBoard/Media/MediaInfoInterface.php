<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Media;

use PHPMachineEmulator\BootType;
use PHPMachineEmulator\Stream\BootableStreamInterface;

interface MediaInfoInterface
{
    /**
     * Get the bootable stream for this media.
     */
    public function stream(): BootableStreamInterface;

    /**
     * Get the boot type for this media.
     */
    public function bootType(): BootType;

    /**
     * Get the media type (e.g., 'cd', 'floppy', 'usb').
     */
    public function mediaType(): MediaType;
}
