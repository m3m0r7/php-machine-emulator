<?php
declare(strict_types=1);
namespace PHPMachineEmulator;

use PHPMachineEmulator\Display\Writer\ScreenWriterFactoryInterface;
use PHPMachineEmulator\IO\IOInterface;
use Psr\Log\LoggerInterface;

interface OptionInterface
{
    public function logger(): LoggerInterface;
    public function IO(): IOInterface;
    public function runtimeClass(): string;
    public function shouldChangeOffset(): bool;
    public function shouldShowHeader(): bool;
    public function screenWriterFactory(): ScreenWriterFactoryInterface;
    public function bootType(): BootType;

    /**
     * Get the memory stream size in bytes.
     * This is the initial size of the emulator memory.
     */
    public function memorySize(): int;

    /**
     * Get the maximum memory stream size in bytes.
     * Memory can auto-expand up to this limit.
     */
    public function maxMemorySize(): int;

    /**
     * Get PHP memory limit string (e.g., '1G', '512M').
     * This will be passed to ini_set('memory_limit', ...).
     */
    public function phpMemoryLimit(): string;
}
