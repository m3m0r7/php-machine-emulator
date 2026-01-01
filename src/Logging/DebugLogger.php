<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Logging;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

final class DebugLogger implements DebugLoggerInterface
{
    use LoggerTrait;

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function isHandling(mixed $level): bool
    {
        if ($this->logger instanceof DebugLoggerInterface) {
            return $this->logger->isHandling($level);
        }

        if ($this->logger instanceof Logger) {
            return $this->logger->isHandling($level);
        }

        return false;
    }

    public function log($level, $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
