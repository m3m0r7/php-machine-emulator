<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Frame;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

interface FrameSetInterface
{
    public function pos(): int;
    public function runtime(): RuntimeInterface;
    public function instruction(): InstructionInterface;
}
