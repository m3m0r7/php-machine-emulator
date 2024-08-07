<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display;

interface CursorInterface
{
    public function reset(): void;
    public function set(int $x, int $y): void;
}
