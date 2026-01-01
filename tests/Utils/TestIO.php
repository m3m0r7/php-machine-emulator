<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\IO\InputInterface;
use PHPMachineEmulator\IO\IOInterface;
use PHPMachineEmulator\IO\OutputInterface;

class TestIO implements IOInterface
{
    public function input(): InputInterface
    {
        return new TestInput();
    }

    public function output(): OutputInterface
    {
        return new TestOutput();
    }

    public function errorOutput(): OutputInterface
    {
        return new TestOutput();
    }
}
