<?php
declare(strict_types=1);
namespace PHPMachineEmulator;

use Psr\Log\LoggerInterface;

interface OptionInterface
{
    public function logger(): LoggerInterface;
}
