<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Display;

use PHPMachineEmulator\Display\Writer\ScreenWriterFactoryInterface;

interface DisplayContextInterface
{
    /**
     * Get the screen writer factory.
     */
    public function screenWriterFactory(): ScreenWriterFactoryInterface;
}
