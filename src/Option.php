<?php
declare(strict_types=1);
namespace PHPMachineEmulator;

use Monolog\Logger;
use PHPMachineEmulator\Display\Writer\ScreenWriterFactoryInterface;
use PHPMachineEmulator\Display\Writer\WindowScreenWriterFactory;
use PHPMachineEmulator\IO\IO;
use PHPMachineEmulator\IO\IOInterface;
use PHPMachineEmulator\Runtime\Runtime;
use Psr\Log\LoggerInterface;

class Option implements OptionInterface
{
    public function __construct(
        protected LoggerInterface $logger = new Logger(BIOS::NAME),
        protected IOInterface $IO = new IO(),
        protected string $runtimeClass = Runtime::class,
        protected bool $shouldChangeOffset = true,
        protected bool $showHeader = false,
        protected ScreenWriterFactoryInterface $screenWriterFactory = new WindowScreenWriterFactory(),
    ) {
    }

    public function logger(): LoggerInterface
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

    public function screenWriterFactory(): ScreenWriterFactoryInterface
    {
        return $this->screenWriterFactory;
    }
}
