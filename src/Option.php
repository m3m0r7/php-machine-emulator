<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use Monolog\Logger;
use PHPMachineEmulator\BIOS\BIOS;
use PHPMachineEmulator\IO\IO;
use PHPMachineEmulator\IO\IOInterface;
use PHPMachineEmulator\Logging\DebugLogger;
use PHPMachineEmulator\Logging\DebugLoggerInterface;
use PHPMachineEmulator\Runtime\Runtime;
use Psr\Log\LoggerInterface;

class Option implements OptionInterface
{
    protected DebugLoggerInterface $logger;

    /**
     * @param LoggerInterface $logger Logger instance
     * @param IOInterface $IO I/O interface
     * @param string $runtimeClass Runtime class name
     * @param bool $shouldChangeOffset Whether to change offset during execution
     * @param bool $showHeader Whether to show header
     */
    public function __construct(
        LoggerInterface $logger = new Logger(BIOS::NAME),
        protected IOInterface $IO = new IO(),
        protected string $runtimeClass = Runtime::class,
        protected bool $shouldChangeOffset = true,
        protected bool $showHeader = false,
    ) {
        $this->logger = $logger instanceof DebugLoggerInterface
            ? $logger
            : new DebugLogger($logger);
    }

    public function logger(): DebugLoggerInterface
    {
        return $this->logger;
    }

    public function IO(): IOInterface
    {
        return $this->IO;
    }

    public function runtimeClass(): string
    {
        return $this->runtimeClass;
    }

    public function shouldChangeOffset(): bool
    {
        return $this->shouldChangeOffset;
    }

    public function shouldShowHeader(): bool
    {
        return $this->showHeader;
    }
}
