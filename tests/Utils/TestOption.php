<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\BootType;
use PHPMachineEmulator\Display\Writer\ScreenWriterFactoryInterface;
use PHPMachineEmulator\IO\IOInterface;
use PHPMachineEmulator\Logging\DebugLogger;
use PHPMachineEmulator\Logging\DebugLoggerInterface;
use PHPMachineEmulator\OptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TestOption implements OptionInterface
{
    private DebugLoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $baseLogger = $logger ?? new NullLogger();
        $this->logger = $baseLogger instanceof DebugLoggerInterface
            ? $baseLogger
            : new DebugLogger($baseLogger);
    }

    public function logger(): DebugLoggerInterface
    {
        return $this->logger;
    }

    public function IO(): IOInterface
    {
        return new TestIO();
    }

    public function runtimeClass(): string
    {
        return TestRuntime::class;
    }

    public function shouldChangeOffset(): bool
    {
        return true;
    }

    public function shouldShowHeader(): bool
    {
        return false;
    }

    public function screenWriterFactory(): ScreenWriterFactoryInterface
    {
        return new TestScreenWriterFactory();
    }

    public function bootType(): BootType
    {
        return BootType::ISO;
    }

    public function memorySize(): int
    {
        return 0x10000; // 64KB for tests
    }

    public function maxMemorySize(): int
    {
        return 0x100000; // 1MB for tests
    }

    public function phpMemoryLimit(): string
    {
        return '256M';
    }
}
