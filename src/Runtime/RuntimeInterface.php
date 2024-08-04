<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Stream\StreamReaderInterface;

interface RuntimeInterface
{
    public function start(int $entrypoint = 0x0000): void;
    public function memoryAccessor(): MemoryAccessorInterface;
    public function execute(int $opcode): ExecutionStatus;
    public function streamReader(): StreamReaderInterface;
    public function register(): RegisterInterface;
    public function frame(): FrameInterface;
    public function option(): OptionInterface;
}
