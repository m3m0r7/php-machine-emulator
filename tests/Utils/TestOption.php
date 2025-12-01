<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\BootType;
use PHPMachineEmulator\Display\Writer\ScreenWriterFactoryInterface;
use PHPMachineEmulator\IO\IOInterface;
use PHPMachineEmulator\OptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TestOption implements OptionInterface
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function logger(): LoggerInterface
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
}
