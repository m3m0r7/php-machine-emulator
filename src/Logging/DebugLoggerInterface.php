<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Logging;

use Psr\Log\LoggerInterface;

interface DebugLoggerInterface extends LoggerInterface
{
    public function isHandling(mixed $level): bool;
}
