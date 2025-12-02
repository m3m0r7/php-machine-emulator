<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Stream\BootableStreamInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Video\VideoInterface;

interface RuntimeInterface
{
    public function runtimeOption(): RuntimeOptionInterface;
    public function context(): RuntimeContextInterface;
    public function start(): void;
    public function addressMap(): AddressMapInterface;
    public function memoryAccessor(): MemoryAccessorInterface;
    /**
     * Execute an instruction.
     *
     * @param int|int[] $opcodes Single opcode or array of opcode bytes
     */
    public function execute(int|array $opcodes): ExecutionStatus;
    public function shutdown(callable $callback): self;
    public function register(): RegisterInterface;
    public function frame(): FrameInterface;
    public function option(): OptionInterface;
    public function video(): VideoInterface;
    public function memory(): MemoryStreamInterface;
    public function bootStream(): BootableStreamInterface;
}
