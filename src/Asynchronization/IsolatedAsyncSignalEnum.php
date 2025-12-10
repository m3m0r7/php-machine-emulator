<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

enum IsolatedAsyncSignalEnum
{
    case MESSAGE;
    case CLOSE;
}
