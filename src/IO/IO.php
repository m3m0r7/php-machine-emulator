<?php

declare(strict_types=1);

namespace PHPMachineEmulator\IO;

class IO implements IOInterface
{
    public function __construct(protected InputInterface $input = new StdIn(), protected OutputInterface $output = new StdOut(), protected OutputInterface $errorOutput = new StdErr())
    {
    }

    public function input(): InputInterface
    {
        return $this->input;
    }

    public function output(): OutputInterface
    {
        return $this->output;
    }

    public function errorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }
}
