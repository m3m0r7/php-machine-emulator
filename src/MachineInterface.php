<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use PHPMachineEmulator\LogicBoard\LogicBoardInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

interface MachineInterface
{
    public function option(): OptionInterface;

    public function logicBoard(): LogicBoardInterface;

    public function runtime(int $entrypoint = 0x0000): RuntimeInterface;
}
