<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface InstructionListInterface
{
    public function register(): RegisterInterface;
    public function getInstructionByOperationCode(int $opcode): InstructionInterface;
    public function setRuntime(RuntimeInterface $runtime): void;
    public function runtime(): RuntimeInterface;
}
