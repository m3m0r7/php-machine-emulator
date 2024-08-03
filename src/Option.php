<?php
declare(strict_types=1);
namespace PHPMachineEmulator;

use Psr\Log\LoggerInterface;

class Option implements OptionInterface
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }
}
