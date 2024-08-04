<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Frame;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class FrameSet implements FrameSetInterface
{
    public function __construct(
        protected RuntimeInterface $runtime,
        protected InstructionInterface $instruction,
        protected int $pos,
        protected mixed $value = null,
    ) {
        $this->runtime = clone $runtime;
        $this->instruction = clone $this->instruction;
    }

    public function instruction(): InstructionInterface
    {
        return $this->instruction;
    }

    public function runtime(): RuntimeInterface
    {
        return $this->runtime;
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function value(): mixed
    {
        return $this->value;
    }
}
