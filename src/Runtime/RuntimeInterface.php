<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\ExecutionStatus;

interface RuntimeInterface
{
    public function start(int $entrypoint = 0x0000): void;
    public function memoryAccessor(): MemoryAccessorInterface;
    public function execute(int $opcode): ExecutionStatus;
}
