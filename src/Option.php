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
    /**
     * @param LoggerInterface $logger Logger instance
     * @param IOInterface $IO I/O interface
     * @param string $runtimeClass Runtime class name
     * @param bool $shouldChangeOffset Whether to change offset during execution
     * @param bool $showHeader Whether to show header
     * @param ScreenWriterFactoryInterface $screenWriterFactory Screen writer factory
     * @param BootType $bootType Boot type
     * @param int $memorySize Initial memory size in bytes (default 2MB)
     * @param int $maxMemorySize Maximum memory size in bytes (default 16MB)
     * @param string $phpMemoryLimit PHP memory limit (default '1G')
     */
    public function __construct(
        protected LoggerInterface $logger = new Logger(BIOS::NAME),
        protected IOInterface $IO = new IO(),
        protected string $runtimeClass = Runtime::class,
        protected bool $shouldChangeOffset = true,
        protected bool $showHeader = false,
        protected ScreenWriterFactoryInterface $screenWriterFactory = new WindowScreenWriterFactory(),
        protected BootType $bootType = BootType::BOOT_SIGNATURE,
        protected int $memorySize = 0x200000,        // 2MB
        protected int $maxMemorySize = 0x1000000,    // 16MB (reasonable for bootloader)
        protected string $phpMemoryLimit = '1G',
    ) {
        // Apply PHP memory limit
        ini_set('memory_limit', $this->phpMemoryLimit);
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

    public function bootType(): BootType
    {
        return $this->bootType;
    }

    public function memorySize(): int
    {
        return $this->memorySize;
    }

    public function maxMemorySize(): int
    {
        return $this->maxMemorySize;
    }

    public function phpMemoryLimit(): string
    {
        return $this->phpMemoryLimit;
    }
}
