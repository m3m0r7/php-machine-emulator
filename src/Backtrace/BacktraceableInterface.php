<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Backtrace;

interface BacktraceableInterface
{
    public function next(): void;
    public function last(): mixed;
}
