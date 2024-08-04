<?php
declare(strict_types=1);
namespace PHPMachineEmulator;

use PHPMachineEmulator\IO\IO;
use PHPMachineEmulator\IO\IOInterface;
use Psr\Log\LoggerInterface;

class Option implements OptionInterface
{
    public function __construct(protected LoggerInterface $logger, protected IOInterface $IO = new IO())
    {
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function IO(): IOInterface
    {
        return $this->IO;
    }
}
