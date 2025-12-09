<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;

interface InstructionExecutorInterface
{
    /**
     * Execute an instruction by fetching and processing opcodes.
     *
     * @return ExecutionStatus The result of instruction execution
     */
    public function execute(RuntimeInterface $runtime): ExecutionStatus;

    /**
     * Get the last executed instruction.
     */
    public function lastInstruction(): ?InstructionInterface;

    /**
     * Get the last executed opcode.
     */
    public function lastOpcodes(): ?array;

    /**
     * Get the instruction pointer before the last execution.
     */
    public function lastInstructionPointer(): int;
}
