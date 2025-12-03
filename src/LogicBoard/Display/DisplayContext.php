<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Display;

use PHPMachineEmulator\Display\Writer\ScreenWriterFactoryInterface;

class DisplayContext implements DisplayContextInterface
{
    public function __construct(
        protected ScreenWriterFactoryInterface $screenWriterFactory,
    ) {
    }

    public function screenWriterFactory(): ScreenWriterFactoryInterface
    {
        return $this->screenWriterFactory;
    }
}
