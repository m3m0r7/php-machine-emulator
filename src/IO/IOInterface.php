<?php

declare(strict_types=1);

namespace PHPMachineEmulator\IO;

interface IOInterface
{
    public function input(): InputInterface;
    public function output(): OutputInterface;
    public function errorOutput(): OutputInterface;
}
