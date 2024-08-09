<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Stream;

use PHPMachineEmulator\Stream\StreamReaderInterface;

interface SIBInterface
{
    public function scale(): int;
    public function index(): int;
    public function base(): int;
}
