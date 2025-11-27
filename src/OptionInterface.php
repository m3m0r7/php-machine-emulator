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
}
