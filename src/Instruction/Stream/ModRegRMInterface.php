<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Stream;

use PHPMachineEmulator\Stream\StreamReaderInterface;

interface ModRegRMInterface
{
    public function mode(): int;
    public function source(): int;
    public function digit(): int;
    public function destination(): int;
    public function registerOrMemoryAddress(): int;
}
