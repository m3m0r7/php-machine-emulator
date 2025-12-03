<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\ExternalDevice;

enum DeviceType: string
{
    case KEYBOARD = 'keyboard';
    case MOUSE = 'mouse';
    case USB_STORAGE = 'usb_storage';
    case SERIAL = 'serial';
    case PARALLEL = 'parallel';
}
