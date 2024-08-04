<?php
declare(strict_types=1);
namespace PHPMachineEmulator;

use PHPMachineEmulator\IO\IOInterface;
use Psr\Log\LoggerInterface;

interface OptionInterface
{
    public function logger(): LoggerInterface;
    public function IO(): IOInterface;
}
