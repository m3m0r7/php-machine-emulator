<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Media;

enum MediaType: string
{
    case CD = 'cd';
    case FLOPPY = 'floppy';
    case USB = 'usb';
}
